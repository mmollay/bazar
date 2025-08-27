<?php
/**
 * Database configuration and connection manager
 * Handles MySQL connection with proper error handling and security
 */

class Database {
    private static $instance = null;
    private $connection = null;
    
    private $host;
    private $database;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    
    private function __construct() {
        // Load database configuration from environment
        $this->host = $_ENV['DB_HOST'] ?? 'localhost';
        $this->database = $_ENV['DB_NAME'] ?? 'bazar_marketplace';
        $this->username = $_ENV['DB_USER'] ?? 'root';
        $this->password = $_ENV['DB_PASS'] ?? '';
        
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check configuration.");
        }
    }
    
    public function getConnection() {
        // Check if connection is still alive
        if ($this->connection === null) {
            $this->connect();
        }
        
        return $this->connection;
    }
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollback() {
        return $this->connection->rollback();
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
    
    public function prepare($sql) {
        return $this->connection->prepare($sql);
    }
    
    public function query($sql) {
        return $this->connection->query($sql);
    }
    
    public function exec($sql) {
        return $this->connection->exec($sql);
    }
    
    // Static helper methods for direct database operations
    public static function query($sql, $params = []) {
        $db = self::getInstance();
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public static function queryOne($sql, $params = []) {
        $db = self::getInstance();
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    public static function insert($table, $data) {
        $db = self::getInstance();
        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';
        
        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";
        $stmt = $db->getConnection()->prepare($sql);
        
        if ($stmt->execute(array_values($data))) {
            return $db->getConnection()->lastInsertId();
        }
        
        return false;
    }
    
    public static function update($table, $data, $conditions) {
        $db = self::getInstance();
        $setClause = implode(' = ?, ', array_keys($data)) . ' = ?';
        
        $whereClause = [];
        $whereParams = [];
        foreach ($conditions as $key => $value) {
            $whereClause[] = "{$key} = ?";
            $whereParams[] = $value;
        }
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE " . implode(' AND ', $whereClause);
        $params = array_merge(array_values($data), $whereParams);
        
        $stmt = $db->getConnection()->prepare($sql);
        return $stmt->execute($params);
    }
    
    public static function delete($table, $conditions) {
        $db = self::getInstance();
        $whereClause = [];
        $params = [];
        
        foreach ($conditions as $key => $value) {
            $whereClause[] = "{$key} = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereClause);
        $stmt = $db->getConnection()->prepare($sql);
        return $stmt->execute($params);
    }
    
    public static function selectOne($table, $conditions = []) {
        $db = self::getInstance();
        $sql = "SELECT * FROM {$table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $key => $value) {
                $whereClause[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        $sql .= " LIMIT 1";
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}

/**
 * Base Model class with common database operations
 */
abstract class BaseModel {
    protected $db;
    protected $table;
    protected $primaryKey = 'id';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Find record by ID
     */
    public function find($id) {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Find records with conditions
     */
    public function where($conditions = [], $orderBy = null, $limit = null) {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $key => $value) {
                $whereClause[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        if ($limit) {
            $sql .= " LIMIT {$limit}";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Create new record
     */
    public function create($data) {
        $columns = array_keys($data);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';
        
        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        
        if ($stmt->execute(array_values($data))) {
            return $this->db->lastInsertId();
        }
        
        return false;
    }
    
    /**
     * Update record
     */
    public function update($id, $data) {
        $columns = array_keys($data);
        $setClause = implode(' = ?, ', $columns) . ' = ?';
        
        $sql = "UPDATE {$this->table} SET {$setClause} WHERE {$this->primaryKey} = ?";
        $params = array_merge(array_values($data), [$id]);
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Delete record
     */
    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
    
    /**
     * Count records
     */
    public function count($conditions = []) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $key => $value) {
                $whereClause[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        return $result['count'] ?? 0;
    }
    
    /**
     * Execute raw SQL query
     */
    public function raw($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Paginate results
     */
    public function paginate($page = 1, $perPage = 20, $conditions = [], $orderBy = null) {
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $totalCount = $this->count($conditions);
        
        // Get records
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $key => $value) {
                $whereClause[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        
        $sql .= " LIMIT {$perPage} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        
        return [
            'data' => $records,
            'total' => $totalCount,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($totalCount / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $totalCount)
        ];
    }
}