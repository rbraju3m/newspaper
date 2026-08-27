<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Shaped like a real FCM endpoint: the endpoint is the identity, so
            // tests that create two subscriptions need two distinct ones.
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->unique()->lexify(str_repeat('?', 40)),
            'public_key' => self::uncompressedP256(),
            'auth_token' => self::base64url(random_bytes(16)),
            'content_encoding' => 'aes128gcm',
            'user_agent' => 'Mozilla/5.0',
            'breaking' => true,
        ];
    }

    /** A reader who has turned breaking alerts off in their account. */
    public function silenced(): static
    {
        return $this->state(fn () => ['breaking' => false]);
    }

    /**
     * A genuine P-256 public key in the uncompressed form the Push API sends.
     *
     * Random bytes will not do. The payload is encrypted by doing ECDH against
     * this key, so the library rejects anything that is not 65 bytes opening
     * with the 0x04 uncompressed-point marker — and openssl rejects anything
     * that is not actually on the curve. A test using a random string never
     * reaches the encryption at all, which is most of what there is to get
     * wrong here.
     */
    public static function uncompressedP256(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $details = openssl_pkey_get_details($key)['ec'];

        return self::base64url(
            "\x04"
            .str_pad($details['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($details['y'], 32, "\0", STR_PAD_LEFT),
        );
    }

    /** What the browser actually sends: base64url, unpadded. */
    public static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
