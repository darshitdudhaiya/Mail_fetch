<?php

namespace App\Http\Middleware;

use Closure;

class HandleCors
{
	protected $allowedOrigins = ['http://localhost:5500'];

	public function handle($request, Closure $next)
	{
		$origin = $request->headers->get('Origin');

		$headers = [];

		if ($origin && in_array($origin, $this->allowedOrigins)) {
			$headers = [
				'Access-Control-Allow-Origin'      => $origin,
				'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
				'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, Accept',
				'Access-Control-Allow-Credentials' => 'true',
			];
		}

		// If this is a preflight request, return 204 with CORS headers immediately
		if ($request->getMethod() === 'OPTIONS') {
			return response()->noContent(204)->withHeaders($headers);
		}

		$response = $next($request);

		if (!empty($headers)) {
			foreach ($headers as $key => $value) {
				$response->headers->set($key, $value);
			}
		}

		return $response;
	}
}
