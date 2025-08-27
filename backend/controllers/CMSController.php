<?php
/**
 * CMS Controller for Legal Pages and Content Management
 * Handles CRUD operations for CMS pages, FAQ, and news/announcements
 */

class CMSController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get all CMS pages with pagination and filtering
     */
    public function getPages($params = []) {
        try {
            $page = (int)Request::get('page', 1);
            $limit = (int)Request::get('limit', 20);
            $offset = ($page - 1) * $limit;
            $type = Request::get('type');
            $language = Request::get('language', 'de');
            $search = Request::get('search');
            
            // Build WHERE clause
            $where = ['language = ?'];
            $values = [$language];
            
            if ($type) {
                $where[] = 'page_type = ?';
                $values[] = $type;
            }
            
            if ($search) {
                $where[] = '(title LIKE ? OR content LIKE ?)';
                $values[] = "%$search%";
                $values[] = "%$search%";
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM cms_pages WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($values);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get pages
            $sql = "SELECT p.*, 
                           u1.username as created_by_username,
                           u2.username as updated_by_username
                    FROM cms_pages p 
                    LEFT JOIN users u1 ON p.created_by = u1.id
                    LEFT JOIN users u2 ON p.updated_by = u2.id
                    WHERE $whereClause 
                    ORDER BY p.is_required DESC, p.updated_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$values, $limit, $offset]);
            $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'pages' => $pages,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting CMS pages: " . $e->getMessage());
            Response::serverError("Failed to retrieve pages");
        }
    }
    
    /**
     * Get a single CMS page by ID or slug
     */
    public function getPage($params) {
        try {
            $identifier = $params['id'] ?? $params['slug'];
            
            if (is_numeric($identifier)) {
                $sql = "SELECT p.*, 
                               u1.username as created_by_username,
                               u2.username as updated_by_username
                        FROM cms_pages p 
                        LEFT JOIN users u1 ON p.created_by = u1.id
                        LEFT JOIN users u2 ON p.updated_by = u2.id
                        WHERE p.id = ?";
            } else {
                $sql = "SELECT p.*, 
                               u1.username as created_by_username,
                               u2.username as updated_by_username
                        FROM cms_pages p 
                        LEFT JOIN users u1 ON p.created_by = u1.id
                        LEFT JOIN users u2 ON p.updated_by = u2.id
                        WHERE p.slug = ?";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$identifier]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$page) {
                Response::notFound("Page not found");
            }
            
            // Get page versions if requested
            if (Request::get('include_versions') === 'true') {
                $versionsSql = "SELECT v.*, u.username as created_by_username
                               FROM cms_page_versions v
                               LEFT JOIN users u ON v.created_by = u.id
                               WHERE v.page_id = ?
                               ORDER BY v.version_number DESC";
                $versionsStmt = $this->db->prepare($versionsSql);
                $versionsStmt->execute([$page['id']]);
                $page['versions'] = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            Response::success($page);
            
        } catch (Exception $e) {
            Logger::error("Error getting CMS page: " . $e->getMessage());
            Response::serverError("Failed to retrieve page");
        }
    }
    
    /**
     * Create a new CMS page
     */
    public function createPage() {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $data = Request::getJson();
            
            // Validate required fields
            $required = ['slug', 'title', 'content'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    Response::badRequest("Field '$field' is required");
                }
            }
            
            // Check for unique slug
            $slugCheckSql = "SELECT id FROM cms_pages WHERE slug = ?";
            $slugStmt = $this->db->prepare($slugCheckSql);
            $slugStmt->execute([$data['slug']]);
            if ($slugStmt->fetch()) {
                Response::badRequest("A page with this slug already exists");
            }
            
            // Prepare page data
            $pageData = [
                'slug' => $data['slug'],
                'title' => $data['title'],
                'content' => $data['content'],
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
                'page_type' => $data['page_type'] ?? 'general',
                'language' => $data['language'] ?? 'de',
                'is_published' => $data['is_published'] ?? false,
                'is_required' => $data['is_required'] ?? false,
                'template' => $data['template'] ?? 'default',
                'legal_version' => $data['legal_version'] ?? '1.0',
                'seo_title' => $data['seo_title'] ?? null,
                'canonical_url' => $data['canonical_url'] ?? null,
                'created_by' => $currentUser['id'],
                'updated_by' => $currentUser['id']
            ];
            
            // Legal pages require review by default
            if ($pageData['page_type'] === 'legal') {
                $pageData['review_required'] = true;
            }
            
            $sql = "INSERT INTO cms_pages (" . implode(',', array_keys($pageData)) . ") 
                    VALUES (" . str_repeat('?,', count($pageData) - 1) . "?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_values($pageData));
            $pageId = $this->db->lastInsertId();
            
            // Create initial version
            $this->createPageVersion($pageId, $pageData, $currentUser['id'], 'Initial version');
            
            // Log admin action
            AdminAuditLogger::log($currentUser['id'], 'create_cms_page', 'cms_pages', $pageId, 
                "Created CMS page: {$pageData['title']}");
            
            Response::success(['id' => $pageId, 'message' => 'Page created successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error creating CMS page: " . $e->getMessage());
            Response::serverError("Failed to create page");
        }
    }
    
    /**
     * Update an existing CMS page
     */
    public function updatePage($params) {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $pageId = $params['id'];
            $data = Request::getJson();
            
            // Get existing page
            $existingSql = "SELECT * FROM cms_pages WHERE id = ?";
            $existingStmt = $this->db->prepare($existingSql);
            $existingStmt->execute([$pageId]);
            $existingPage = $existingStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingPage) {
                Response::notFound("Page not found");
            }
            
            // Check slug uniqueness if changed
            if (isset($data['slug']) && $data['slug'] !== $existingPage['slug']) {
                $slugCheckSql = "SELECT id FROM cms_pages WHERE slug = ? AND id != ?";
                $slugStmt = $this->db->prepare($slugCheckSql);
                $slugStmt->execute([$data['slug'], $pageId]);
                if ($slugStmt->fetch()) {
                    Response::badRequest("A page with this slug already exists");
                }
            }
            
            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'slug', 'title', 'content', 'meta_description', 'meta_keywords',
                'page_type', 'language', 'is_published', 'is_required', 'template',
                'legal_version', 'seo_title', 'canonical_url'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }
            
            $updateData['updated_by'] = $currentUser['id'];
            
            // Legal pages require review when content changes
            if ($existingPage['page_type'] === 'legal' && 
                (isset($data['content']) || isset($data['legal_version']))) {
                $updateData['review_required'] = true;
                $updateData['last_reviewed_at'] = null;
            }
            
            // Build UPDATE query
            $setParts = [];
            foreach ($updateData as $field => $value) {
                $setParts[] = "$field = ?";
            }
            
            $sql = "UPDATE cms_pages SET " . implode(', ', $setParts) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...array_values($updateData), $pageId]);
            
            // Create new version if content changed
            if (isset($data['content']) || isset($data['title'])) {
                $changeSummary = $data['change_summary'] ?? 'Content updated';
                $this->createPageVersion($pageId, array_merge($existingPage, $updateData), 
                                       $currentUser['id'], $changeSummary);
            }
            
            // Log admin action
            AdminAuditLogger::log($currentUser['id'], 'update_cms_page', 'cms_pages', $pageId, 
                "Updated CMS page: {$existingPage['title']}");
            
            Response::success(['message' => 'Page updated successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error updating CMS page: " . $e->getMessage());
            Response::serverError("Failed to update page");
        }
    }
    
    /**
     * Delete a CMS page
     */
    public function deletePage($params) {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            if (!$currentUser || !$currentUser['is_admin']) {
                Response::unauthorized("Admin access required");
            }
            
            $pageId = $params['id'];
            
            // Get page info
            $pageSql = "SELECT title, is_required FROM cms_pages WHERE id = ?";
            $pageStmt = $this->db->prepare($pageSql);
            $pageStmt->execute([$pageId]);
            $page = $pageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$page) {
                Response::notFound("Page not found");
            }
            
            // Prevent deletion of required legal pages
            if ($page['is_required']) {
                Response::badRequest("Required legal pages cannot be deleted");
            }
            
            // Delete page (versions will be deleted by CASCADE)
            $deleteSql = "DELETE FROM cms_pages WHERE id = ?";
            $deleteStmt = $this->db->prepare($deleteSql);
            $deleteStmt->execute([$pageId]);
            
            // Log admin action
            AdminAuditLogger::log($currentUser['id'], 'delete_cms_page', 'cms_pages', $pageId, 
                "Deleted CMS page: {$page['title']}");
            
            Response::success(['message' => 'Page deleted successfully']);
            
        } catch (Exception $e) {
            Logger::error("Error deleting CMS page: " . $e->getMessage());
            Response::serverError("Failed to delete page");
        }
    }
    
    /**
     * Get FAQ categories and items
     */
    public function getFAQ() {
        try {
            $categoryId = Request::get('category_id');
            $search = Request::get('search');
            
            if ($categoryId) {
                // Get specific category with items
                $categorySql = "SELECT * FROM faq_categories WHERE id = ? AND is_active = 1";
                $categoryStmt = $this->db->prepare($categorySql);
                $categoryStmt->execute([$categoryId]);
                $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$category) {
                    Response::notFound("FAQ category not found");
                }
                
                $itemsSql = "SELECT * FROM faq_items WHERE category_id = ? AND is_active = 1 ORDER BY sort_order, id";
                $itemsStmt = $this->db->prepare($itemsSql);
                $itemsStmt->execute([$categoryId]);
                $category['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                Response::success($category);
                
            } elseif ($search) {
                // Search FAQ items
                $searchSql = "SELECT i.*, c.name as category_name 
                             FROM faq_items i 
                             JOIN faq_categories c ON i.category_id = c.id 
                             WHERE (MATCH(i.question, i.answer, i.keywords) AGAINST(? IN BOOLEAN MODE) 
                                   OR i.question LIKE ? OR i.answer LIKE ?) 
                             AND i.is_active = 1 AND c.is_active = 1 
                             ORDER BY i.is_featured DESC, i.helpful_count DESC";
                
                $searchTerm = "%$search%";
                $searchStmt = $this->db->prepare($searchSql);
                $searchStmt->execute([$search, $searchTerm, $searchTerm]);
                $items = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
                
                Response::success(['items' => $items]);
                
            } else {
                // Get all categories with item counts
                $categoriesSql = "SELECT c.*, COUNT(i.id) as item_count 
                                 FROM faq_categories c 
                                 LEFT JOIN faq_items i ON c.id = i.category_id AND i.is_active = 1 
                                 WHERE c.is_active = 1 
                                 GROUP BY c.id 
                                 ORDER BY c.sort_order, c.name";
                
                $categoriesStmt = $this->db->prepare($categoriesSql);
                $categoriesStmt->execute();
                $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get featured items
                $featuredSql = "SELECT i.*, c.name as category_name 
                               FROM faq_items i 
                               JOIN faq_categories c ON i.category_id = c.id 
                               WHERE i.is_featured = 1 AND i.is_active = 1 AND c.is_active = 1 
                               ORDER BY i.helpful_count DESC 
                               LIMIT 10";
                
                $featuredStmt = $this->db->prepare($featuredSql);
                $featuredStmt->execute();
                $featured = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);
                
                Response::success([
                    'categories' => $categories,
                    'featured' => $featured
                ]);
            }
            
        } catch (Exception $e) {
            Logger::error("Error getting FAQ: " . $e->getMessage());
            Response::serverError("Failed to retrieve FAQ");
        }
    }
    
    /**
     * Submit FAQ feedback
     */
    public function submitFAQFeedback($params) {
        try {
            $faqId = $params['id'];
            $data = Request::getJson();
            
            $isHelpful = isset($data['helpful']) ? (bool)$data['helpful'] : null;
            $comment = $data['comment'] ?? null;
            
            if ($isHelpful === null) {
                Response::badRequest("Feedback value required");
            }
            
            $currentUser = AuthMiddleware::getCurrentUser();
            $userId = $currentUser ? $currentUser['id'] : null;
            $ipAddress = Request::getClientIp();
            
            // Check if user/IP already gave feedback
            $checkSql = "SELECT id FROM faq_feedback 
                        WHERE faq_id = ? AND " . 
                        ($userId ? "user_id = ?" : "ip_address = ?");
            
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute($userId ? [$faqId, $userId] : [$faqId, $ipAddress]);
            
            if ($checkStmt->fetch()) {
                Response::badRequest("You have already provided feedback for this FAQ");
            }
            
            // Insert feedback
            $insertSql = "INSERT INTO faq_feedback (faq_id, user_id, ip_address, is_helpful, feedback_comment) 
                         VALUES (?, ?, ?, ?, ?)";
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([$faqId, $userId, $ipAddress, $isHelpful, $comment]);
            
            // Update FAQ counters
            $counterField = $isHelpful ? 'helpful_count' : 'not_helpful_count';
            $updateSql = "UPDATE faq_items SET $counterField = $counterField + 1 WHERE id = ?";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([$faqId]);
            
            Response::success(['message' => 'Thank you for your feedback']);
            
        } catch (Exception $e) {
            Logger::error("Error submitting FAQ feedback: " . $e->getMessage());
            Response::serverError("Failed to submit feedback");
        }
    }
    
    /**
     * Get news and announcements
     */
    public function getNews() {
        try {
            $page = (int)Request::get('page', 1);
            $limit = (int)Request::get('limit', 10);
            $offset = ($page - 1) * $limit;
            $category = Request::get('category');
            $featured = Request::get('featured');
            
            $where = ['is_published = 1', 'publish_at <= NOW()', '(expires_at IS NULL OR expires_at > NOW())'];
            $values = [];
            
            if ($category) {
                $where[] = 'category = ?';
                $values[] = $category;
            }
            
            if ($featured === 'true') {
                $where[] = 'is_featured = 1';
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM news_announcements WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($values);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get news items
            $sql = "SELECT id, title, slug, excerpt, featured_image_url, category, priority,
                           is_featured, banner_text, banner_type, view_count, created_at
                    FROM news_announcements 
                    WHERE $whereClause 
                    ORDER BY priority DESC, is_featured DESC, created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$values, $limit, $offset]);
            $news = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success([
                'news' => $news,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            Logger::error("Error getting news: " . $e->getMessage());
            Response::serverError("Failed to retrieve news");
        }
    }
    
    /**
     * Get single news article
     */
    public function getNewsArticle($params) {
        try {
            $slug = $params['slug'];
            
            $sql = "SELECT * FROM news_announcements 
                    WHERE slug = ? AND is_published = 1 
                    AND publish_at <= NOW() 
                    AND (expires_at IS NULL OR expires_at > NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$slug]);
            $article = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$article) {
                Response::notFound("Article not found");
            }
            
            // Increment view count
            $updateSql = "UPDATE news_announcements SET view_count = view_count + 1 WHERE id = ?";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([$article['id']]);
            
            Response::success($article);
            
        } catch (Exception $e) {
            Logger::error("Error getting news article: " . $e->getMessage());
            Response::serverError("Failed to retrieve article");
        }
    }
    
    /**
     * Create page version for audit trail
     */
    private function createPageVersion($pageId, $pageData, $userId, $changeSummary) {
        try {
            // Get current max version number
            $versionSql = "SELECT COALESCE(MAX(version_number), 0) as max_version 
                          FROM cms_page_versions WHERE page_id = ?";
            $versionStmt = $this->db->prepare($versionSql);
            $versionStmt->execute([$pageId]);
            $maxVersion = $versionStmt->fetch(PDO::FETCH_ASSOC)['max_version'];
            
            $newVersion = $maxVersion + 1;
            
            $versionData = [
                'page_id' => $pageId,
                'version_number' => $newVersion,
                'title' => $pageData['title'],
                'content' => $pageData['content'],
                'meta_description' => $pageData['meta_description'] ?? null,
                'meta_keywords' => $pageData['meta_keywords'] ?? null,
                'legal_version' => $pageData['legal_version'] ?? '1.0',
                'change_summary' => $changeSummary,
                'created_by' => $userId
            ];
            
            $insertSql = "INSERT INTO cms_page_versions (" . implode(',', array_keys($versionData)) . ") 
                         VALUES (" . str_repeat('?,', count($versionData) - 1) . "?)";
            
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute(array_values($versionData));
            
        } catch (Exception $e) {
            Logger::error("Error creating page version: " . $e->getMessage());
            // Don't throw exception as this is not critical for main operation
        }
    }
}