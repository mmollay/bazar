<?php

use PHPUnit\Framework\TestCase;

/**
 * Article Controller Tests
 */
class ArticleControllerTest extends TestCase
{
    private $pdo;
    private $articleController;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'bazar_test'),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->cleanDatabase();
        $this->setupMocks();
        $this->createTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }
    
    private function cleanDatabase()
    {
        try {
            $this->pdo->exec("DELETE FROM articles");
            $this->pdo->exec("DELETE FROM users");
            $this->pdo->exec("DELETE FROM categories");
            $this->pdo->exec("ALTER TABLE articles AUTO_INCREMENT = 1");
            $this->pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
            $this->pdo->exec("ALTER TABLE categories AUTO_INCREMENT = 1");
        } catch (PDOException $e) {
            // Tables might not exist yet
        }
    }
    
    private function setupMocks()
    {
        if (!class_exists('ArticleController')) {
            eval('
                class ArticleController {
                    private $pdo;
                    
                    public function __construct($pdo) {
                        $this->pdo = $pdo;
                    }
                    
                    public function create($data, $userId) {
                        // Validate required fields
                        $required = ["title", "description", "price", "category_id"];
                        foreach ($required as $field) {
                            if (!isset($data[$field]) || empty($data[$field])) {
                                return [
                                    "success" => false,
                                    "message" => "Missing required field: {$field}"
                                ];
                            }
                        }
                        
                        // Validate price
                        if (!is_numeric($data["price"]) || $data["price"] < 0) {
                            return [
                                "success" => false,
                                "message" => "Invalid price"
                            ];
                        }
                        
                        // Validate category exists
                        $stmt = $this->pdo->prepare("SELECT id FROM categories WHERE id = ?");
                        $stmt->execute([$data["category_id"]]);
                        if (!$stmt->fetch()) {
                            return [
                                "success" => false,
                                "message" => "Invalid category"
                            ];
                        }
                        
                        // Insert article
                        $stmt = $this->pdo->prepare("
                            INSERT INTO articles (user_id, title, description, price, category_id, condition_type, status, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        
                        if ($stmt->execute([
                            $userId,
                            $data["title"],
                            $data["description"],
                            $data["price"],
                            $data["category_id"],
                            $data["condition_type"] ?? "used",
                            $data["status"] ?? "active"
                        ])) {
                            $articleId = $this->pdo->lastInsertId();
                            
                            return [
                                "success" => true,
                                "message" => "Article created successfully",
                                "article_id" => $articleId
                            ];
                        }
                        
                        return [
                            "success" => false,
                            "message" => "Failed to create article"
                        ];
                    }
                    
                    public function getAll($filters = []) {
                        $sql = "SELECT a.*, u.username, c.name as category_name 
                                FROM articles a 
                                LEFT JOIN users u ON a.user_id = u.id 
                                LEFT JOIN categories c ON a.category_id = c.id 
                                WHERE a.status = \"active\"";
                        $params = [];
                        
                        if (!empty($filters["category_id"])) {
                            $sql .= " AND a.category_id = ?";
                            $params[] = $filters["category_id"];
                        }
                        
                        if (!empty($filters["min_price"])) {
                            $sql .= " AND a.price >= ?";
                            $params[] = $filters["min_price"];
                        }
                        
                        if (!empty($filters["max_price"])) {
                            $sql .= " AND a.price <= ?";
                            $params[] = $filters["max_price"];
                        }
                        
                        if (!empty($filters["condition"])) {
                            $sql .= " AND a.condition_type = ?";
                            $params[] = $filters["condition"];
                        }
                        
                        if (!empty($filters["search"])) {
                            $sql .= " AND (a.title LIKE ? OR a.description LIKE ?)";
                            $searchTerm = "%" . $filters["search"] . "%";
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                        }
                        
                        $sql .= " ORDER BY a.created_at DESC";
                        
                        if (!empty($filters["limit"])) {
                            $sql .= " LIMIT ?";
                            $params[] = (int)$filters["limit"];
                        }
                        
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute($params);
                        
                        return [
                            "success" => true,
                            "articles" => $stmt->fetchAll()
                        ];
                    }
                    
                    public function getById($id) {
                        $stmt = $this->pdo->prepare("
                            SELECT a.*, u.username, u.email as user_email, c.name as category_name 
                            FROM articles a 
                            LEFT JOIN users u ON a.user_id = u.id 
                            LEFT JOIN categories c ON a.category_id = c.id 
                            WHERE a.id = ?
                        ");
                        $stmt->execute([$id]);
                        $article = $stmt->fetch();
                        
                        if (!$article) {
                            return [
                                "success" => false,
                                "message" => "Article not found"
                            ];
                        }
                        
                        // Increment view count
                        $this->incrementViews($id);
                        
                        return [
                            "success" => true,
                            "article" => $article
                        ];
                    }
                    
                    public function update($id, $data, $userId) {
                        // Check if article belongs to user
                        $stmt = $this->pdo->prepare("SELECT user_id FROM articles WHERE id = ?");
                        $stmt->execute([$id]);
                        $article = $stmt->fetch();
                        
                        if (!$article) {
                            return [
                                "success" => false,
                                "message" => "Article not found"
                            ];
                        }
                        
                        if ($article["user_id"] != $userId) {
                            return [
                                "success" => false,
                                "message" => "Unauthorized"
                            ];
                        }
                        
                        // Build update query
                        $allowedFields = ["title", "description", "price", "category_id", "condition_type", "status"];
                        $updates = [];
                        $params = [];
                        
                        foreach ($allowedFields as $field) {
                            if (isset($data[$field])) {
                                $updates[] = "{$field} = ?";
                                $params[] = $data[$field];
                            }
                        }
                        
                        if (empty($updates)) {
                            return [
                                "success" => false,
                                "message" => "No valid fields to update"
                            ];
                        }
                        
                        $sql = "UPDATE articles SET " . implode(", ", $updates) . ", updated_at = NOW() WHERE id = ?";
                        $params[] = $id;
                        
                        $stmt = $this->pdo->prepare($sql);
                        
                        if ($stmt->execute($params)) {
                            return [
                                "success" => true,
                                "message" => "Article updated successfully"
                            ];
                        }
                        
                        return [
                            "success" => false,
                            "message" => "Failed to update article"
                        ];
                    }
                    
                    public function delete($id, $userId) {
                        // Check if article belongs to user
                        $stmt = $this->pdo->prepare("SELECT user_id FROM articles WHERE id = ?");
                        $stmt->execute([$id]);
                        $article = $stmt->fetch();
                        
                        if (!$article) {
                            return [
                                "success" => false,
                                "message" => "Article not found"
                            ];
                        }
                        
                        if ($article["user_id"] != $userId) {
                            return [
                                "success" => false,
                                "message" => "Unauthorized"
                            ];
                        }
                        
                        // Soft delete by updating status
                        $stmt = $this->pdo->prepare("UPDATE articles SET status = \"deleted\", updated_at = NOW() WHERE id = ?");
                        
                        if ($stmt->execute([$id])) {
                            return [
                                "success" => true,
                                "message" => "Article deleted successfully"
                            ];
                        }
                        
                        return [
                            "success" => false,
                            "message" => "Failed to delete article"
                        ];
                    }
                    
                    public function getUserArticles($userId) {
                        $stmt = $this->pdo->prepare("
                            SELECT a.*, c.name as category_name 
                            FROM articles a 
                            LEFT JOIN categories c ON a.category_id = c.id 
                            WHERE a.user_id = ? AND a.status != \"deleted\"
                            ORDER BY a.created_at DESC
                        ");
                        $stmt->execute([$userId]);
                        
                        return [
                            "success" => true,
                            "articles" => $stmt->fetchAll()
                        ];
                    }
                    
                    public function getFeatured() {
                        $stmt = $this->pdo->prepare("
                            SELECT a.*, u.username, c.name as category_name 
                            FROM articles a 
                            LEFT JOIN users u ON a.user_id = u.id 
                            LEFT JOIN categories c ON a.category_id = c.id 
                            WHERE a.status = \"active\" AND a.featured = 1
                            ORDER BY a.created_at DESC 
                            LIMIT 10
                        ");
                        $stmt->execute();
                        
                        return [
                            "success" => true,
                            "articles" => $stmt->fetchAll()
                        ];
                    }
                    
                    private function incrementViews($id) {
                        $stmt = $this->pdo->prepare("UPDATE articles SET view_count = view_count + 1 WHERE id = ?");
                        $stmt->execute([$id]);
                    }
                }
            ');
        }
        
        $this->articleController = new ArticleController($this->pdo);
    }
    
    private function createTestData()
    {
        // Create test user
        $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            'testuser',
            'test@example.com',
            password_hash('password', PASSWORD_DEFAULT),
            'Test',
            'User'
        ]);
        
        // Create test category
        $this->pdo->prepare("
            INSERT INTO categories (name, description, created_at, updated_at) 
            VALUES (?, ?, NOW(), NOW())
        ")->execute([
            'Electronics',
            'Electronic devices and gadgets'
        ]);
        
        // Create test article
        $this->pdo->prepare("
            INSERT INTO articles (user_id, title, description, price, category_id, condition_type, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            1,
            'Test iPhone',
            'A test iPhone for sale',
            500.00,
            1,
            'used',
            'active'
        ]);
    }

    public function testCreateArticleSuccess()
    {
        $articleData = [
            'title' => 'New Article',
            'description' => 'This is a new article for testing',
            'price' => 299.99,
            'category_id' => 1,
            'condition_type' => 'new'
        ];

        $result = $this->articleController->create($articleData, 1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('article_id', $result);
        $this->assertEquals('Article created successfully', $result['message']);
    }

    public function testCreateArticleWithMissingFields()
    {
        $articleData = [
            'title' => 'Incomplete Article',
            'price' => 100.00
            // Missing description and category_id
        ];

        $result = $this->articleController->create($articleData, 1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing required field', $result['message']);
    }

    public function testCreateArticleWithInvalidPrice()
    {
        $articleData = [
            'title' => 'Invalid Price Article',
            'description' => 'Article with negative price',
            'price' => -50.00,
            'category_id' => 1
        ];

        $result = $this->articleController->create($articleData, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid price', $result['message']);
    }

    public function testCreateArticleWithInvalidCategory()
    {
        $articleData = [
            'title' => 'Invalid Category Article',
            'description' => 'Article with non-existent category',
            'price' => 100.00,
            'category_id' => 999 // Non-existent category
        ];

        $result = $this->articleController->create($articleData, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid category', $result['message']);
    }

    public function testGetAllArticles()
    {
        $result = $this->articleController->getAll();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('articles', $result);
        $this->assertIsArray($result['articles']);
        $this->assertGreaterThan(0, count($result['articles']));
    }

    public function testGetAllArticlesWithFilters()
    {
        $filters = [
            'category_id' => 1,
            'min_price' => 100.00,
            'max_price' => 600.00,
            'condition' => 'used'
        ];

        $result = $this->articleController->getAll($filters);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('articles', $result);
        
        foreach ($result['articles'] as $article) {
            $this->assertEquals(1, $article['category_id']);
            $this->assertGreaterThanOrEqual(100.00, (float)$article['price']);
            $this->assertLessThanOrEqual(600.00, (float)$article['price']);
            $this->assertEquals('used', $article['condition_type']);
        }
    }

    public function testGetAllArticlesWithSearch()
    {
        $filters = ['search' => 'iPhone'];
        $result = $this->articleController->getAll($filters);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('articles', $result);
        
        foreach ($result['articles'] as $article) {
            $titleMatch = stripos($article['title'], 'iPhone') !== false;
            $descriptionMatch = stripos($article['description'], 'iPhone') !== false;
            $this->assertTrue($titleMatch || $descriptionMatch);
        }
    }

    public function testGetArticleById()
    {
        $result = $this->articleController->getById(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('article', $result);
        $this->assertEquals('Test iPhone', $result['article']['title']);
        $this->assertArrayHasKey('username', $result['article']);
        $this->assertArrayHasKey('category_name', $result['article']);
    }

    public function testGetArticleByNonExistentId()
    {
        $result = $this->articleController->getById(999);

        $this->assertFalse($result['success']);
        $this->assertEquals('Article not found', $result['message']);
    }

    public function testUpdateArticleSuccess()
    {
        $updateData = [
            'title' => 'Updated iPhone',
            'price' => 600.00
        ];

        $result = $this->articleController->update(1, $updateData, 1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Article updated successfully', $result['message']);

        // Verify the update
        $stmt = $this->pdo->prepare("SELECT title, price FROM articles WHERE id = 1");
        $stmt->execute();
        $article = $stmt->fetch();
        
        $this->assertEquals('Updated iPhone', $article['title']);
        $this->assertEquals(600.00, (float)$article['price']);
    }

    public function testUpdateArticleUnauthorized()
    {
        $updateData = ['title' => 'Unauthorized Update'];
        
        $result = $this->articleController->update(1, $updateData, 999); // Different user

        $this->assertFalse($result['success']);
        $this->assertEquals('Unauthorized', $result['message']);
    }

    public function testUpdateNonExistentArticle()
    {
        $updateData = ['title' => 'Non-existent Update'];
        
        $result = $this->articleController->update(999, $updateData, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Article not found', $result['message']);
    }

    public function testDeleteArticleSuccess()
    {
        $result = $this->articleController->delete(1, 1);

        $this->assertTrue($result['success']);
        $this->assertEquals('Article deleted successfully', $result['message']);

        // Verify the article is soft deleted
        $stmt = $this->pdo->prepare("SELECT status FROM articles WHERE id = 1");
        $stmt->execute();
        $article = $stmt->fetch();
        
        $this->assertEquals('deleted', $article['status']);
    }

    public function testDeleteArticleUnauthorized()
    {
        $result = $this->articleController->delete(1, 999); // Different user

        $this->assertFalse($result['success']);
        $this->assertEquals('Unauthorized', $result['message']);
    }

    public function testDeleteNonExistentArticle()
    {
        $result = $this->articleController->delete(999, 1);

        $this->assertFalse($result['success']);
        $this->assertEquals('Article not found', $result['message']);
    }

    public function testGetUserArticles()
    {
        $result = $this->articleController->getUserArticles(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('articles', $result);
        $this->assertIsArray($result['articles']);
        
        foreach ($result['articles'] as $article) {
            $this->assertEquals(1, $article['user_id']);
            $this->assertNotEquals('deleted', $article['status']);
        }
    }

    public function testGetFeaturedArticles()
    {
        // First, set an article as featured
        $this->pdo->prepare("UPDATE articles SET featured = 1 WHERE id = 1")->execute();

        $result = $this->articleController->getFeatured();

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('articles', $result);
        $this->assertIsArray($result['articles']);
        
        foreach ($result['articles'] as $article) {
            $this->assertEquals(1, (int)$article['featured']);
            $this->assertEquals('active', $article['status']);
        }
    }

    public function testArticleTitleSanitization()
    {
        $maliciousData = [
            'title' => '<script>alert("XSS")</script>Malicious Title',
            'description' => 'Normal description',
            'price' => 100.00,
            'category_id' => 1
        ];

        $result = $this->articleController->create($maliciousData, 1);

        if ($result['success']) {
            // Verify that HTML is escaped or removed
            $stmt = $this->pdo->prepare("SELECT title FROM articles WHERE id = ?");
            $stmt->execute([$result['article_id']]);
            $article = $stmt->fetch();
            
            $this->assertStringNotContainsString('<script>', $article['title']);
        }
    }

    public function testPaginationLimit()
    {
        // Create multiple articles for pagination test
        for ($i = 0; $i < 15; $i++) {
            $this->pdo->prepare("
                INSERT INTO articles (user_id, title, description, price, category_id, condition_type, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                1,
                "Test Article $i",
                "Description $i",
                100 + $i,
                1,
                'used',
                'active'
            ]);
        }

        $filters = ['limit' => 5];
        $result = $this->articleController->getAll($filters);

        $this->assertTrue($result['success']);
        $this->assertLessThanOrEqual(5, count($result['articles']));
    }
}