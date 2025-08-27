<?php
// Bazar API - Simple Index for Testing

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/bazar/backend/api/v1';
$path = str_replace($basePath, '', parse_url($requestUri, PHP_URL_PATH));

// Simple routing
$response = [];

switch ($path) {
    case '/auth/profile':
        // Return empty profile for unauthenticated users
        http_response_code(401);
        $response = ['error' => 'Not authenticated'];
        break;
        
    case '/categories':
        // Return sample categories
        $response = [
            'status' => 'success',
            'data' => [
                ['id' => 1, 'name' => 'Elektronik', 'slug' => 'elektronik', 'icon' => 'fa fa-laptop'],
                ['id' => 2, 'name' => 'Fahrzeuge', 'slug' => 'fahrzeuge', 'icon' => 'fa fa-car'],
                ['id' => 3, 'name' => 'Immobilien', 'slug' => 'immobilien', 'icon' => 'fa fa-home'],
                ['id' => 4, 'name' => 'Mode', 'slug' => 'mode', 'icon' => 'fa fa-tshirt'],
                ['id' => 5, 'name' => 'Möbel', 'slug' => 'mobel', 'icon' => 'fa fa-couch'],
                ['id' => 6, 'name' => 'Sport', 'slug' => 'sport', 'icon' => 'fa fa-football']
            ]
        ];
        break;
        
    case '/articles/featured':
    case '/featured':
        // Return sample featured articles
        $response = [
            'status' => 'success',
            'data' => [
                [
                    'id' => 1,
                    'title' => 'iPhone 14 Pro',
                    'price' => 999,
                    'location' => 'Wien',
                    'image' => '/bazar/frontend/assets/images/placeholder.jpg'
                ],
                [
                    'id' => 2,
                    'title' => 'Gaming PC RTX 4090',
                    'price' => 2499,
                    'location' => 'Graz',
                    'image' => '/bazar/frontend/assets/images/placeholder.jpg'
                ],
                [
                    'id' => 3,
                    'title' => 'Designer Sofa',
                    'price' => 799,
                    'location' => 'Linz',
                    'image' => '/bazar/frontend/assets/images/placeholder.jpg'
                ]
            ]
        ];
        break;
        
    case '/search':
        // Return sample search results
        $response = [
            'status' => 'success',
            'data' => [],
            'total' => 0
        ];
        break;
        
    case '/search/suggestions':
        // Return sample suggestions
        $query = $_GET['q'] ?? '';
        $suggestions = [];
        if ($query) {
            $suggestions = [
                $query . ' neu',
                $query . ' gebraucht',
                $query . ' günstig'
            ];
        }
        $response = [
            'status' => 'success',
            'suggestions' => $suggestions
        ];
        break;
        
    default:
        // 404 for unknown endpoints
        http_response_code(404);
        $response = ['error' => 'Not Found'];
}

echo json_encode($response);
?>