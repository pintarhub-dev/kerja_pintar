<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FCMService
{
    public static function send($token, $title, $body, $data = [])
    {
        if (empty($token)) {
            return false;
        }

        // 1. Ambil Access Token dari JSON File (OAuth2)
        $credentialsPath = storage_path('app/firebase_credentials.json');

        if (!file_exists($credentialsPath)) {
            Log::error('FCM: File credentials tidak ditemukan di ' . $credentialsPath);
            return false;
        }

        $client = new GoogleClient();
        $client->setAuthConfig($credentialsPath);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        // Refresh token kalau expired
        $client->fetchAccessTokenWithAssertion();
        $accessToken = $client->getAccessToken();

        if (!isset($accessToken['access_token'])) {
            Log::error('FCM: Gagal mendapatkan Access Token Google.');
            return false;
        }

        $formattedData = [];
        foreach ($data as $key => $value) {
            $formattedData[$key] = (string) $value;
        }

        // 2. Siapkan Payload (Format HTTP v1)
        $projectId = json_decode(file_get_contents($credentialsPath))->project_id;
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                // Data tambahan (opsional, misal buat navigasi pas diklik)
                'data' => $formattedData,
            ]
        ];

        // 3. Tembak API Google
        $response = Http::withToken($accessToken['access_token'])
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $payload);

        if ($response->successful()) {
            Log::info("FCM Sent to {$token}: {$title}");
            return true;
        } else {
            Log::error("FCM Error: " . $response->body());
            return false;
        }
    }
}
