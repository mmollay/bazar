<?php

use PHPUnit\Framework\TestCase;

/**
 * Article Model Tests
 */
class ArticleTest extends TestCase
{
    private $pdo;
    private $article;

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
        
        require_once __DIR__ . '/../../../backend/models/Article.php';
        
        if (!class_exists('BaseModel')) {
            eval('
                class BaseModel {
                    protected $db;
                    protected $table;
                    
                    public function __construct($db = null) {
                        global $pdo;
                        $this->db = $db ?: $pdo;
                    }
                    
                    public function create($data) {
                        $columns = array_keys($data);
                        $placeholders = array_fill(0, count($columns), "?");
                        
                        $sql = "INSERT INTO {$this->table} (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")";
                        $stmt = $this->db->prepare($sql);
                        $stmt->execute(array_values($data));
                        
                        return $this->db->lastInsertId();
                    }
                    
                    public function findById($id) {
                        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
                        $stmt->execute([$id]);
                        return $stmt->fetch();
                    }
                    
                    public function update($id, $data) {
                        $columns = array_keys($data);
                        $setClause = implode(", ", array_map(fn($col) => "$col = ?", $columns));
                        
                        $sql = "UPDATE {$this->table} SET $setClause WHERE id = ?";
                        $stmt = $this->db->prepare($sql);
                        $values = array_values($data);
                        $values[] = $id;
                        
                        return $stmt->execute($values);
                    }
                }
            ');
        }
        
        $this->article = new Article($this->pdo);
        $this->createTestUser();
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
            $this->pdo->exec("ALTER TABLE articles AUTO_INCREMENT = 1");
            $this->pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
        } catch (PDOException $e) {
            // Tables might not exist yet
        }
    }
    
    private function createTestUser()
    {
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
        
        return $this->pdo->lastInsertId();
    }

    public function testCreateArticle()
    {
        $articleData = [
            'user_id' => 1,
            'title' => 'Test Article',
            'description' => 'This is a test article description',
            'price' => 99.99,
            'category_id' => 1,
            'condition_type' => 'new',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $articleId = $this->article->create($articleData);

        $this->assertIsInt($articleId);
        $this->assertGreaterThan(0, $articleId);

        // Verify article was created
        $stmt = $this->pdo->prepare("SELECT * FROM articles WHERE id = ?");
        $stmt->execute([$articleId]);
        $result = $stmt->fetch();

        $this->assertNotNull($result);
        $this->assertEquals('Test Article', $result['title']);
        $this->assertEquals(99.99, (float)$result['price']);
        $this->assertEquals('new', $result['condition_type']);
    }

    public function testFindByUser()
    {
        // Create test articles
        $this->createTestArticles();

        $articles = $this->article->findByUser(1);

        $this->assertIsArray($articles);
        $this->assertCount(2, $articles);
        
        foreach ($articles as $article) {
            $this->assertEquals(1, $article['user_id']);
        }
    }

    public function testFindByCategory()
    {
        $this->createTestArticles();

        $articles = $this->article->findByCategory(1);

        $this->assertIsArray($articles);
        $this->assertGreaterThan(0, count($articles));
        
        foreach ($articles as $article) {
            $this->assertEquals(1, $article['category_id']);
        }
    }

    public function testSearch()
    {
        $this->createTestArticles();

        $results = $this->article->search('iPhone');

        $this->assertIsArray($results);
        $this->assertGreaterThan(0, count($results));
        
        foreach ($results as $article) {
            $titleMatches = stripos($article['title'], 'iPhone') !== false;
            $descriptionMatches = stripos($article['description'], 'iPhone') !== false;
            $this->assertTrue($titleMatches || $descriptionMatches);
        }
    }

    public function testUpdateStatus()
    {
        $articleId = $this->createTestArticle();

        $result = $this->article->updateStatus($articleId, 'sold');

        $this->assertTrue($result);

        // Verify status was updated
        $stmt = $this->pdo->prepare("SELECT status FROM articles WHERE id = ?");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();

        $this->assertEquals('sold', $article['status']);
    }

    public function testIncrementViews()
    {
        $articleId = $this->createTestArticle();

        $result = $this->article->incrementViews($articleId);

        $this->assertTrue($result);

        // Verify views were incremented
        $stmt = $this->pdo->prepare("SELECT view_count FROM articles WHERE id = ?");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();

        $this->assertEquals(1, (int)$article['view_count']);

        // Increment again
        $this->article->incrementViews($articleId);
        
        $stmt->execute([$articleId]);
        $article = $stmt->fetch();
        $this->assertEquals(2, (int)$article['view_count']);
    }

    public function testGetActiveArticles()
    {
        // Create articles with different statuses
        $this->createTestArticle(['status' => 'active']);
        $this->createTestArticle(['status' => 'inactive']);
        $this->createTestArticle(['status' => 'sold']);
        $this->createTestArticle(['status' => 'active']);

        $activeArticles = $this->article->getActive();

        $this->assertIsArray($activeArticles);
        $this->assertCount(2, $activeArticles);
        
        foreach ($activeArticles as $article) {
            $this->assertEquals('active', $article['status']);
        }
    }

    public function testGetFeaturedArticles()
    {
        // Create featured and non-featured articles
        $this->createTestArticle(['featured' => 1]);
        $this->createTestArticle(['featured' => 0]);
        $this->createTestArticle(['featured' => 1]);

        $featuredArticles = $this->article->getFeatured();

        $this->assertIsArray($featuredArticles);
        $this->assertCount(2, $featuredArticles);
        
        foreach ($featuredArticles as $article) {
            $this->assertEquals(1, (int)$article['featured']);
        }
    }

    public function testPriceValidation()
    {
        $this->expectException(InvalidArgumentException::class);
        
        $articleData = [
            'user_id' => 1,
            'title' => 'Test Article',
            'description' => 'Test description',
            'price' => -10.00, // Invalid negative price
            'category_id' => 1,
            'condition_type' => 'new',
            'status' => 'active'
        ];

        $this->article->create($articleData);
    }

    public function testArticleSlugGeneration()
    {
        $articleData = [
            'user_id' => 1,
            'title' => 'iPhone 13 Pro Max - Excellent Condition!',
            'description' => 'Test description',
            'price' => 999.99,
            'category_id' => 1,
            'condition_type' => 'used',
            'status' => 'active'
        ];

        $articleId = $this->article->create($articleData);
        $article = $this->article->findById($articleId);

        $this->assertNotNull($article);
        
        if (isset($article['slug'])) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $article['slug']);
            $this->assertStringNotContainsString(' ', $article['slug']);
        }
    }

    private function createTestArticles()
    {
        $articles = [
            [
                'user_id' => 1,
                'title' => 'iPhone 13 Pro',
                'description' => 'Latest iPhone model in excellent condition',
                'price' => 899.99,
                'category_id' => 1,
                'condition_type' => 'used',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            [
                'user_id' => 1,
                'title' => 'Samsung Galaxy S21',
                'description' => 'Android flagship phone',
                'price' => 699.99,
                'category_id' => 1,
                'condition_type' => 'new',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];

        foreach ($articles as $articleData) {
            $this->article->create($articleData);
        }
    }

    private function createTestArticle($overrides = [])
    {
        $defaults = [
            'user_id' => 1,
            'title' => 'Test Article',
            'description' => 'Test description',
            'price' => 99.99,
            'category_id' => 1,
            'condition_type' => 'new',
            'status' => 'active',
            'featured' => 0,
            'view_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $articleData = array_merge($defaults, $overrides);
        return $this->article->create($articleData);
    }
}