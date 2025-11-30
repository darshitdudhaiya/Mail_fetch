<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public $id;
    public $name;
    public $email;
    public $microsoft_email;
    public $microsoft_access_token;
    public $microsoft_refresh_token;
    public $microsoft_token_expires_at;
    public $microsoft_graph_user_id;

    /**
     * Construct a new user object from an array of attributes.
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Store user data in the session.
     *
     * @param  array  $userData
     * @return void
     */
    public static function storeInSession(array $userData): void
    {
        session([
            'microsoft_azure_user'    => [
                'id'                         => $userData['id'],
                'name'                       => $userData['name'],
                'email'                      => $userData['email'],
                'microsoft_email'            => $userData['microsoft_email'],
                'microsoft_graph_user_id'    => $userData['microsoft_graph_user_id'], 
                'microsoft_access_token'     => $userData['microsoft_access_token'],
                'microsoft_refresh_token'    => $userData['microsoft_refresh_token'],
                'microsoft_token_expires_at' => $userData['microsoft_token_expires_at'],
            ],
            'microsoft_azure_user_id' => $userData['id'],
        ]);
    }

    /**
     * Retrieve the user from the session.
     *
     * @return static|null
     */
    public static function fromSession(): ?self
    {
        $userData = session('microsoft_azure_user');

        return $userData ? new self($userData) : null;
    }

    /**
     * Update the stored session user data with the current object state.
     *
     * @return void
     */
   public function updateSession(): void
{
    session([
        'microsoft_azure_user' => [
            'id'                         => $this->id,
            'name'                       => $this->name,
            'email'                      => $this->email,
            'microsoft_email'            => $this->microsoft_email,
            'microsoft_graph_user_id'    => $this->microsoft_graph_user_id ?? null,
            // assume the tokens already encrypted when set on $this
            'microsoft_access_token'     => $this->microsoft_access_token,
            'microsoft_refresh_token'    => $this->microsoft_refresh_token,
            'microsoft_token_expires_at' => $this->microsoft_token_expires_at,
        ],
    ]);
}



    /**
     * Remove user data from the session (logout).
     *
     * @return void
     */
    public static function clearSession(): void
    {
        session()->forget(['microsoft_azure_user', 'microsoft_azure_user_id']);
    }

    /**
     * Check if a Microsoft Azure user is currently authenticated.
     *
     * @return bool
     */
    public static function isAuthenticated(): bool
    {
        return session()->has('microsoft_azure_user');
    }

    /**
     * Helper: Decrypt and return the Microsoft access token.
     *
     * @return string|null
     */
    public function getDecryptedAccessToken(): ?string
    {
        return $this->microsoft_access_token
            ? decrypt($this->microsoft_access_token)
            : null;
    }

    /**
     * Helper: Decrypt and return the Microsoft refresh token.
     *
     * @return string|null
     */
    public function getDecryptedRefreshToken(): ?string
    {
        return $this->microsoft_refresh_token
            ? decrypt($this->microsoft_refresh_token)
            : null;
    }
}
