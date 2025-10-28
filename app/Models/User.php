<?php

namespace App\Models;

class User
{
    public $id;
    public $name;
    public $email;
    public $microsoft_email;
    public $microsoft_access_token;
    public $microsoft_refresh_token;
    public $microsoft_token_expires_at;

    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Store user in session
     */
    public static function storeInSession($userData)
    {
        session([
            'user' => $userData,
            'user_id' => $userData['id']
        ]);
    }

    /**
     * Get user from session
     */
    public static function fromSession()
    {
        $userData = session('user');
        return $userData ? new self($userData) : null;
    }

    /**
     * Update user in session
     */
    public function updateSession()
    {
        session(['user' => (array) $this]);
    }

    /**
     * Clear user from session
     */
    public static function clearSession()
    {
        session()->forget(['user', 'user_id']);
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated()
    {
        return session()->has('user');
    }
}