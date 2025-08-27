<?php

use PHPUnit\Framework\TestCase;

/**
 * Message Model Tests
 */
class MessageTest extends TestCase
{
    private $pdo;
    private $message;

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
        
        require_once __DIR__ . '/../../../backend/models/Message.php';
        
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
        
        $this->message = new Message($this->pdo);
        $this->createTestUsers();
        $this->createTestConversation();
    }
    
    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }
    
    private function cleanDatabase()
    {
        try {
            $this->pdo->exec("DELETE FROM messages");
            $this->pdo->exec("DELETE FROM conversations");
            $this->pdo->exec("DELETE FROM users");
            $this->pdo->exec("ALTER TABLE messages AUTO_INCREMENT = 1");
            $this->pdo->exec("ALTER TABLE conversations AUTO_INCREMENT = 1");
            $this->pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
        } catch (PDOException $e) {
            // Tables might not exist yet
        }
    }
    
    private function createTestUsers()
    {
        $users = [
            ['testuser1', 'user1@example.com'],
            ['testuser2', 'user2@example.com']
        ];
        
        foreach ($users as $user) {
            $this->pdo->prepare("
                INSERT INTO users (username, email, password_hash, first_name, last_name, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $user[0],
                $user[1],
                password_hash('password', PASSWORD_DEFAULT),
                'Test',
                'User'
            ]);
        }
    }
    
    private function createTestConversation()
    {
        $this->pdo->prepare("
            INSERT INTO conversations (user1_id, user2_id, created_at, updated_at) 
            VALUES (?, ?, NOW(), NOW())
        ")->execute([1, 2]);
    }

    public function testCreateMessage()
    {
        $messageData = [
            'conversation_id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'content' => 'Hello, is this item still available?',
            'message_type' => 'text',
            'status' => 'sent',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $messageId = $this->message->create($messageData);

        $this->assertIsInt($messageId);
        $this->assertGreaterThan(0, $messageId);

        // Verify message was created
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();

        $this->assertNotNull($result);
        $this->assertEquals('Hello, is this item still available?', $result['content']);
        $this->assertEquals(1, $result['sender_id']);
        $this->assertEquals(2, $result['receiver_id']);
    }

    public function testFindByConversation()
    {
        // Create test messages
        $this->createTestMessages();

        $messages = $this->message->findByConversation(1);

        $this->assertIsArray($messages);
        $this->assertCount(3, $messages);
        
        foreach ($messages as $msg) {
            $this->assertEquals(1, $msg['conversation_id']);
        }
    }

    public function testFindUnreadMessages()
    {
        // Create messages with different statuses
        $this->createTestMessage(['status' => 'sent', 'receiver_id' => 2]);
        $this->createTestMessage(['status' => 'delivered', 'receiver_id' => 2]);
        $this->createTestMessage(['status' => 'read', 'receiver_id' => 2]);

        $unreadMessages = $this->message->findUnread(2);

        $this->assertIsArray($unreadMessages);
        $this->assertCount(2, $unreadMessages);
        
        foreach ($unreadMessages as $msg) {
            $this->assertEquals(2, $msg['receiver_id']);
            $this->assertNotEquals('read', $msg['status']);
        }
    }

    public function testMarkAsRead()
    {
        $messageId = $this->createTestMessage(['status' => 'sent']);

        $result = $this->message->markAsRead($messageId);

        $this->assertTrue($result);

        // Verify status was updated
        $stmt = $this->pdo->prepare("SELECT status, read_at FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        $this->assertEquals('read', $message['status']);
        $this->assertNotNull($message['read_at']);
    }

    public function testMarkAsDelivered()
    {
        $messageId = $this->createTestMessage(['status' => 'sent']);

        $result = $this->message->markAsDelivered($messageId);

        $this->assertTrue($result);

        // Verify status was updated
        $stmt = $this->pdo->prepare("SELECT status, delivered_at FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        $this->assertEquals('delivered', $message['status']);
        $this->assertNotNull($message['delivered_at']);
    }

    public function testDeleteMessage()
    {
        $messageId = $this->createTestMessage();

        $result = $this->message->delete($messageId);

        $this->assertTrue($result);

        // Verify message was deleted or marked as deleted
        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        // Message should either be deleted or marked as deleted
        $this->assertTrue($message === false || $message['deleted_at'] !== null);
    }

    public function testGetConversationSummary()
    {
        $this->createTestMessages();

        $summary = $this->message->getConversationSummary(1);

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_messages', $summary);
        $this->assertArrayHasKey('unread_count', $summary);
        $this->assertArrayHasKey('last_message', $summary);
        
        $this->assertEquals(3, $summary['total_messages']);
    }

    public function testMessageEncryption()
    {
        $sensitiveContent = 'This is sensitive personal information';
        
        $messageData = [
            'conversation_id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'content' => $sensitiveContent,
            'message_type' => 'text',
            'status' => 'sent',
            'encrypted' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $messageId = $this->message->create($messageData);

        // Verify message was encrypted in database
        $stmt = $this->pdo->prepare("SELECT content, encrypted FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();

        if ($result['encrypted']) {
            // If encryption is implemented, content should not be plain text
            $this->assertNotEquals($sensitiveContent, $result['content']);
        }
    }

    public function testMessageWithAttachment()
    {
        $messageData = [
            'conversation_id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'content' => 'Check out this image',
            'message_type' => 'image',
            'status' => 'sent',
            'attachment_path' => '/uploads/messages/image.jpg',
            'attachment_size' => 1024000,
            'attachment_mime' => 'image/jpeg',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $messageId = $this->message->create($messageData);

        $stmt = $this->pdo->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $result = $stmt->fetch();

        $this->assertEquals('image', $result['message_type']);
        $this->assertEquals('/uploads/messages/image.jpg', $result['attachment_path']);
        $this->assertEquals(1024000, $result['attachment_size']);
        $this->assertEquals('image/jpeg', $result['attachment_mime']);
    }

    public function testMessageSizeLimit()
    {
        $longContent = str_repeat('This is a very long message. ', 1000); // ~27KB content
        
        $messageData = [
            'conversation_id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'content' => $longContent,
            'message_type' => 'text',
            'status' => 'sent',
            'created_at' => date('Y-m-d H:i:s')
        ];

        // This should either succeed (if no size limit) or throw an exception
        try {
            $messageId = $this->message->create($messageData);
            $this->assertIsInt($messageId);
        } catch (Exception $e) {
            // If there's a size limit, an exception should be thrown
            $this->assertStringContainsString('size', strtolower($e->getMessage()));
        }
    }

    private function createTestMessages()
    {
        $messages = [
            [
                'conversation_id' => 1,
                'sender_id' => 1,
                'receiver_id' => 2,
                'content' => 'Hello!',
                'message_type' => 'text',
                'status' => 'read'
            ],
            [
                'conversation_id' => 1,
                'sender_id' => 2,
                'receiver_id' => 1,
                'content' => 'Hi there!',
                'message_type' => 'text',
                'status' => 'delivered'
            ],
            [
                'conversation_id' => 1,
                'sender_id' => 1,
                'receiver_id' => 2,
                'content' => 'How are you?',
                'message_type' => 'text',
                'status' => 'sent'
            ]
        ];

        foreach ($messages as $messageData) {
            $messageData['created_at'] = date('Y-m-d H:i:s');
            $this->message->create($messageData);
        }
    }

    private function createTestMessage($overrides = [])
    {
        $defaults = [
            'conversation_id' => 1,
            'sender_id' => 1,
            'receiver_id' => 2,
            'content' => 'Test message',
            'message_type' => 'text',
            'status' => 'sent',
            'created_at' => date('Y-m-d H:i:s')
        ];

        $messageData = array_merge($defaults, $overrides);
        return $this->message->create($messageData);
    }
}