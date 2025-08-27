<?php
/**
 * Admin Settings Controller
 * Handles system settings, configuration, and email templates
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

class AdminSettingsController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get system settings by group
     */
    public function getSettings() {
        try {
            AdminMiddleware::handle();
            
            $group = Request::get('group', 'all');
            
            $whereClause = '';
            $params = [];
            
            if ($group !== 'all') {
                $whereClause = 'WHERE group_name = ?';
                $params[] = $group;
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    id, setting_key, setting_value, setting_type, 
                    description, is_public, group_name, updated_at
                FROM system_settings
                {$whereClause}
                ORDER BY group_name ASC, setting_key ASC
            ");
            $stmt->execute($params);
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group settings by category
            $groupedSettings = [];
            foreach ($settings as $setting) {
                $groupedSettings[$setting['group_name']][] = $setting;
            }
            
            // Get available groups
            $stmt = $this->db->prepare("SELECT DISTINCT group_name FROM system_settings ORDER BY group_name ASC");
            $stmt->execute();
            $availableGroups = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            Response::success([
                'settings' => $groupedSettings,
                'available_groups' => $availableGroups
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error getting settings: ' . $e->getMessage());
            Response::serverError('Failed to load settings');
        }
    }
    
    /**
     * Update system setting
     */
    public function updateSetting() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'setting_key' => 'required',
                'setting_value' => 'required',
                'setting_type' => 'in:string,number,boolean,json'
            ]);
            
            // Check if setting exists
            $stmt = $this->db->prepare("SELECT * FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$data['setting_key']]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$setting) {
                Response::notFound('Setting not found');
            }
            
            // Validate setting value based on type
            $validatedValue = $this->validateSettingValue($data['setting_value'], $setting['setting_type']);
            if ($validatedValue === false) {
                Response::error('Invalid setting value for type: ' . $setting['setting_type']);
            }
            
            // Special validation for critical settings
            $this->validateCriticalSetting($data['setting_key'], $validatedValue);
            
            // Update setting
            $stmt = $this->db->prepare("
                UPDATE system_settings 
                SET setting_value = ?, updated_by = ?, updated_at = NOW()
                WHERE setting_key = ?
            ");
            $stmt->execute([$validatedValue, $currentUser['id'], $data['setting_key']]);
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'update_setting',
                'system_settings',
                $setting['id'],
                "Updated setting '{$data['setting_key']}' to '{$validatedValue}'"
            );
            
            // Clear cache if needed
            CacheService::delete('system_settings');
            
            Response::success(['message' => 'Setting updated successfully']);
            
        } catch (Exception $e) {
            Logger::error('Error updating setting: ' . $e->getMessage());
            Response::serverError('Failed to update setting');
        }
    }
    
    /**
     * Create new system setting
     */
    public function createSetting() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            // Only super admins can create settings
            if ($currentUser['admin_role'] !== 'super_admin') {
                Response::forbidden('Only super admins can create system settings');
            }
            
            $data = Request::validate([
                'setting_key' => 'required|max:100',
                'setting_value' => 'required',
                'setting_type' => 'required|in:string,number,boolean,json',
                'description' => 'max:500',
                'is_public' => '',
                'group_name' => 'required|max:50'
            ]);
            
            // Check if setting already exists
            $stmt = $this->db->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$data['setting_key']]);
            if ($stmt->fetch()) {
                Response::error('Setting with this key already exists');
            }
            
            // Validate setting value
            $validatedValue = $this->validateSettingValue($data['setting_value'], $data['setting_type']);
            if ($validatedValue === false) {
                Response::error('Invalid setting value for type: ' . $data['setting_type']);
            }
            
            // Create setting
            $stmt = $this->db->prepare("
                INSERT INTO system_settings 
                (setting_key, setting_value, setting_type, description, is_public, group_name, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['setting_key'],
                $validatedValue,
                $data['setting_type'],
                $data['description'] ?? '',
                isset($data['is_public']) ? (bool)$data['is_public'] : false,
                $data['group_name'],
                $currentUser['id']
            ]);
            
            $settingId = $this->db->lastInsertId();
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'create_setting',
                'system_settings',
                $settingId,
                "Created setting '{$data['setting_key']}'"
            );
            
            Response::success(['message' => 'Setting created successfully', 'id' => $settingId]);
            
        } catch (Exception $e) {
            Logger::error('Error creating setting: ' . $e->getMessage());
            Response::serverError('Failed to create setting');
        }
    }
    
    /**
     * Delete system setting
     */
    public function deleteSetting() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            // Only super admins can delete settings
            if ($currentUser['admin_role'] !== 'super_admin') {
                Response::forbidden('Only super admins can delete system settings');
            }
            
            $settingKey = Request::get('setting_key');
            if (!$settingKey) {
                Response::error('Setting key is required');
            }
            
            // Check if setting exists and is not critical
            $stmt = $this->db->prepare("SELECT * FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$settingKey]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$setting) {
                Response::notFound('Setting not found');
            }
            
            // Prevent deletion of critical settings
            $criticalSettings = [
                'site_name', 'admin_email', 'maintenance_mode', 'registration_enabled'
            ];
            
            if (in_array($settingKey, $criticalSettings)) {
                Response::error('Cannot delete critical system setting');
            }
            
            // Delete setting
            $stmt = $this->db->prepare("DELETE FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$settingKey]);
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'delete_setting',
                'system_settings',
                $setting['id'],
                "Deleted setting '{$settingKey}'"
            );
            
            Response::success(['message' => 'Setting deleted successfully']);
            
        } catch (Exception $e) {
            Logger::error('Error deleting setting: ' . $e->getMessage());
            Response::serverError('Failed to delete setting');
        }
    }
    
    /**
     * Get email templates
     */
    public function getEmailTemplates() {
        try {
            AdminMiddleware::handle();
            
            $stmt = $this->db->prepare("
                SELECT 
                    et.*,
                    u.username as created_by_username
                FROM email_templates et
                LEFT JOIN users u ON et.created_by = u.id
                ORDER BY et.name ASC
            ");
            $stmt->execute();
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            Response::success(['templates' => $templates]);
            
        } catch (Exception $e) {
            Logger::error('Error getting email templates: ' . $e->getMessage());
            Response::serverError('Failed to load email templates');
        }
    }
    
    /**
     * Update email template
     */
    public function updateEmailTemplate() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'template_id' => 'required',
                'subject' => 'required|max:255',
                'body' => 'required',
                'variables' => '',
                'is_active' => ''
            ]);
            
            // Validate template exists
            $stmt = $this->db->prepare("SELECT * FROM email_templates WHERE id = ?");
            $stmt->execute([$data['template_id']]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                Response::notFound('Email template not found');
            }
            
            // Validate variables JSON
            $variables = [];
            if (!empty($data['variables'])) {
                $variables = json_decode($data['variables'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Response::error('Invalid JSON format for variables');
                }
            }
            
            // Update template
            $stmt = $this->db->prepare("
                UPDATE email_templates 
                SET subject = ?, body = ?, variables = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['subject'],
                $data['body'],
                json_encode($variables),
                isset($data['is_active']) ? (bool)$data['is_active'] : true,
                $data['template_id']
            ]);
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'update_email_template',
                'email_templates',
                $data['template_id'],
                "Updated email template '{$template['name']}'"
            );
            
            Response::success(['message' => 'Email template updated successfully']);
            
        } catch (Exception $e) {
            Logger::error('Error updating email template: ' . $e->getMessage());
            Response::serverError('Failed to update email template');
        }
    }
    
    /**
     * Create new email template
     */
    public function createEmailTemplate() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'name' => 'required|max:100',
                'subject' => 'required|max:255',
                'body' => 'required',
                'variables' => '',
                'is_active' => ''
            ]);
            
            // Check if template name already exists
            $stmt = $this->db->prepare("SELECT id FROM email_templates WHERE name = ?");
            $stmt->execute([$data['name']]);
            if ($stmt->fetch()) {
                Response::error('Email template with this name already exists');
            }
            
            // Validate variables JSON
            $variables = [];
            if (!empty($data['variables'])) {
                $variables = json_decode($data['variables'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Response::error('Invalid JSON format for variables');
                }
            }
            
            // Create template
            $stmt = $this->db->prepare("
                INSERT INTO email_templates (name, subject, body, variables, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['subject'],
                $data['body'],
                json_encode($variables),
                isset($data['is_active']) ? (bool)$data['is_active'] : true,
                $currentUser['id']
            ]);
            
            $templateId = $this->db->lastInsertId();
            
            // Log the action
            $this->logAdminAction(
                $currentUser['id'],
                'create_email_template',
                'email_templates',
                $templateId,
                "Created email template '{$data['name']}'"
            );
            
            Response::success(['message' => 'Email template created successfully', 'id' => $templateId]);
            
        } catch (Exception $e) {
            Logger::error('Error creating email template: ' . $e->getMessage());
            Response::serverError('Failed to create email template');
        }
    }
    
    /**
     * Test email template
     */
    public function testEmailTemplate() {
        try {
            AdminMiddleware::handle();
            $currentUser = AdminMiddleware::getCurrentUser();
            
            $data = Request::validate([
                'template_id' => 'required',
                'test_email' => 'required|email'
            ]);
            
            // Get template
            $stmt = $this->db->prepare("SELECT * FROM email_templates WHERE id = ?");
            $stmt->execute([$data['template_id']]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                Response::notFound('Email template not found');
            }
            
            // Replace template variables with test data
            $testVariables = [
                'first_name' => 'Test User',
                'username' => 'testuser',
                'email' => $data['test_email'],
                'article_title' => 'Test Article',
                'article_url' => 'http://example.com/article/123',
                'reset_url' => 'http://example.com/reset/token123',
                'rejection_reason' => 'Test rejection reason'
            ];
            
            $subject = $template['subject'];
            $body = $template['body'];
            
            foreach ($testVariables as $key => $value) {
                $subject = str_replace("{{{$key}}}", $value, $subject);
                $body = str_replace("{{{$key}}}", $value, $body);
            }
            
            // In a real implementation, you would actually send the email
            // For now, we'll just return the processed template
            Logger::info('Test email template', [
                'template_id' => $data['template_id'],
                'test_email' => $data['test_email'],
                'admin_id' => $currentUser['id']
            ]);
            
            Response::success([
                'message' => 'Test email processed successfully',
                'processed_subject' => $subject,
                'processed_body' => $body
            ]);
            
        } catch (Exception $e) {
            Logger::error('Error testing email template: ' . $e->getMessage());
            Response::serverError('Failed to test email template');
        }
    }
    
    /**
     * Get system information
     */
    public function getSystemInfo() {
        try {
            AdminMiddleware::handle();
            
            $info = [
                'php_version' => phpversion(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'database_version' => $this->getDatabaseVersion(),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timezone' => date_default_timezone_get(),
                'extensions' => $this->getLoadedExtensions()
            ];
            
            Response::success(['system_info' => $info]);
            
        } catch (Exception $e) {
            Logger::error('Error getting system info: ' . $e->getMessage());
            Response::serverError('Failed to load system information');
        }
    }
    
    /**
     * Validate setting value based on type
     */
    private function validateSettingValue($value, $type) {
        switch ($type) {
            case 'boolean':
                if (is_bool($value)) return $value;
                if (is_string($value)) {
                    return in_array(strtolower($value), ['true', '1', 'yes', 'on']) ? 'true' : 'false';
                }
                return false;
                
            case 'number':
                if (is_numeric($value)) return (string)$value;
                return false;
                
            case 'json':
                if (is_array($value)) {
                    return json_encode($value);
                }
                if (is_string($value)) {
                    json_decode($value);
                    return json_last_error() === JSON_ERROR_NONE ? $value : false;
                }
                return false;
                
            case 'string':
            default:
                return (string)$value;
        }
    }
    
    /**
     * Validate critical settings
     */
    private function validateCriticalSetting($key, $value) {
        switch ($key) {
            case 'max_upload_size':
                if (!is_numeric($value) || $value < 1024) { // Minimum 1KB
                    throw new Exception('Upload size must be at least 1024 bytes');
                }
                break;
                
            case 'admin_email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Admin email must be a valid email address');
                }
                break;
                
            case 'allowed_file_types':
                $types = json_decode($value, true);
                if (!is_array($types) || empty($types)) {
                    throw new Exception('Allowed file types must be a non-empty array');
                }
                break;
        }
    }
    
    /**
     * Get database version
     */
    private function getDatabaseVersion() {
        try {
            $stmt = $this->db->prepare("SELECT VERSION()");
            $stmt->execute();
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            return 'Unknown';
        }
    }
    
    /**
     * Get loaded PHP extensions
     */
    private function getLoadedExtensions() {
        $extensions = get_loaded_extensions();
        $importantExtensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'gd', 'imagick', 'redis'];
        
        $result = [];
        foreach ($importantExtensions as $ext) {
            $result[$ext] = in_array($ext, $extensions);
        }
        
        return $result;
    }
    
    /**
     * Log admin action
     */
    private function logAdminAction($adminId, $action, $targetType = null, $targetId = null, $description = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO admin_logs (admin_id, action, target_type, target_id, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $adminId,
                $action,
                $targetType,
                $targetId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
        } catch (Exception $e) {
            Logger::error('Failed to log admin action: ' . $e->getMessage());
        }
    }
}