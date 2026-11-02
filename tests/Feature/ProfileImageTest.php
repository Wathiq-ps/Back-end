<?php

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// A real 1x1 PNG, so the `image` rule's mime sniffing passes without GD.
const PROFILE_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'jwt.secret' => str_repeat('s', 32),
    ]);
    DB::purge('sqlite');
    Schema::create('users', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('email');
        $table->string('phone')->nullable();
        $table->string('status')->default('active');
        $table->string('locale')->default('en');
        $table->string('name')->nullable();
        $table->string('nationality')->nullable();
        $table->string('signature_path')->nullable();
        $table->string('document_type')->nullable();
        $table->string('document_number')->nullable();
        $table->date('date_of_birth')->nullable();
        $table->string('profile_image_path')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('phone_verified_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('roles', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('code');
    });
    Schema::create('tenant_memberships', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('user_id');
        $table->string('role_id');
        $table->string('status');
    });

    DB::table('users')->insert(['id' => 'user-1', 'email' => 'owner@example.test']);
    $this->user = User::find('user-1');
    $this->token = app(JwtService::class)->issueAccessToken($this->user);

    Storage::fake('public');
});

afterEach(function () {
    DB::purge('sqlite');
});

function profileImageUpload(string $name = 'avatar.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(PROFILE_TEST_PNG));
}

test('a user uploads a profile image and gets its public url back', function () {
    $response = $this->withToken($this->token)
        ->post('/api/v1/profile/image', ['profile_image' => profileImageUpload()], ['Accept' => 'application/json']);

    $response->assertOk();
    $path = $this->user->fresh()->profile_image_path;
    expect($path)->toStartWith('avatars/user-1/');
    Storage::disk('public')->assertExists($path);
    expect($response->json('user.profile_image_url'))->toBe(Storage::disk('public')->url($path));
});

test('a new profile image replaces the old file', function () {
    $this->withToken($this->token)->post('/api/v1/profile/image', ['profile_image' => profileImageUpload()]);
    $first = $this->user->fresh()->profile_image_path;

    $this->withToken($this->token)->post('/api/v1/profile/image', ['profile_image' => profileImageUpload('second.png')])
        ->assertOk();

    $second = $this->user->fresh()->profile_image_path;
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('the upload must be an image', function (array $payload) {
    $this->withToken($this->token)
        ->post('/api/v1/profile/image', $payload, ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('profile_image');

    expect($this->user->fresh()->profile_image_path)->toBeNull();
})->with([
    'missing' => [[]],
    'not an image' => [['profile_image' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')]],
]);

test('changing the profile image requires a signed-in user', function () {
    $this->post('/api/v1/profile/image', ['profile_image' => profileImageUpload()], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});
