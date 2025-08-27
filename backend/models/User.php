<?php
/**
 * User Model
 */

class User extends BaseModel {
    protected $table = 'users';
    
    /**
     * Create new user with password hashing
     */
    public function createUser($data) {
        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        
        return $this->create($data);
    }
    
    /**
     * Find user by email
     */
    public function findByEmail($email) {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    }
    
    /**
     * Find user by username
     */
    public function findByUsername($username) {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch();
    }
    
    /**
     * Verify password
     */
    public function verifyPassword($user, $password) {
        return password_verify($password, $user['password_hash']);
    }
    
    /**
     * Update last login
     */
    public function updateLastLogin($userId) {
        return $this->update($userId, ['last_login_at' => date('Y-m-d H:i:s')]);
    }
}