<?php

use App\Models\PropertyMedia;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'jwt.secret' => str_repeat('s', 32),
    ]);
    DB::purge('sqlite');
    // A real local disk in a throwaway root, not Storage::fake(): the fake
    // swaps in its own unsigned temporaryUrl, so the signed storage.local
    // route that photo URLs point at would never be exercised.
    config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/property-photos')]);
    Storage::forgetDisk('local');
    Schema::create('tenants', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('slug');
    });
    Schema::create('currencies', function (Blueprint $table) {
        $table->string('code')->primary();
        $table->integer('exponent');
    });
    Schema::create('users', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name')->nullable();
        $table->string('email');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('identity_documents', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('user_id');
        $table->string('status');
    });
    Schema::create('properties', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('tenant_id');
        $table->string('reference');
        $table->string('owner_id');
        $table->string('title');
        $table->text('description')->nullable();
        $table->string('type');
        $table->string('listing_type');
        $table->string('status');
        $table->bigInteger('price_amount');
        $table->string('price_currency');
        $table->string('price_unit')->nullable();
        $table->decimal('area_sqm', 10, 2);
        $table->integer('rooms')->nullable();
        $table->integer('bathrooms')->nullable();
        $table->integer('floor_number')->nullable();
        $table->boolean('is_furnished')->default(false);
        $table->string('city');
        $table->string('district');
        $table->string('building_number')->nullable();
        $table->string('address_line')->nullable();
        $table->double('latitude')->nullable();
        $table->double('longitude')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('property_media', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('property_id');
        $table->string('disk');
        $table->string('path');
        $table->boolean('is_cover');
        $table->integer('sort_order');
    });
    Schema::create('amenities', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('code');
    });
    Schema::create('property_amenities', function (Blueprint $table) {
        $table->string('property_id');
        $table->string('amenity_id');
    });
    Schema::create('property_ratings', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('property_id');
        $table->integer('score');
    });
    Schema::create('ownership_documents', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('property_id');
        $table->string('type');
        $table->string('status');
        $table->text('rejection_reason')->nullable();
        $table->timestamps();
    });
    // lawyer.approved asks whether the caller holds the lawyer role.
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

    DB::table('tenants')->insert(['id' => 'tenant-1', 'slug' => 'default']);
    DB::table('currencies')->insert(['code' => 'JOD', 'exponent' => 3]);
    DB::table('users')->insert([
        'id' => 'owner-1', 'name' => 'Amal', 'email' => 'amal@example.test', 'created_at' => '2026-01-15 09:00:00',
    ]);
    DB::table('users')->insert(['id' => 'other-1', 'name' => 'Sami', 'email' => 'sami@example.test']);
    DB::table('identity_documents')->insert([
        ['id' => 'doc-1', 'user_id' => 'owner-1', 'status' => 'approved'],
        ['id' => 'doc-2', 'user_id' => 'other-1', 'status' => 'approved'],
    ]);
});

afterEach(function () {
    DB::purge('sqlite');
    Storage::disk('local')->deleteDirectory('properties');
    Storage::forgetDisk('local');
});

function listing(array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('properties')->insert([
        'id' => $id, 'tenant_id' => 'tenant-1', 'reference' => 'PR-'.strtoupper(Str::random(8)),
        'owner_id' => 'owner-1', 'title' => 'Apartment for rent in Ramallah', 'type' => 'apartment',
        'listing_type' => 'rent', 'status' => 'published', 'price_amount' => 450000, 'price_currency' => 'JOD',
        'price_unit' => 'per_month', 'area_sqm' => 120, 'rooms' => 3, 'city' => 'Ramallah', 'district' => 'Al-Masyoun',
        'address_line' => 'Al-Masyoun, Ramallah', 'latitude' => 31.9038, 'longitude' => 35.2034,
        'published_at' => '2026-10-01 10:00:00', ...$overrides,
    ]);

    return $id;
}

function photo(string $propertyId): void
{
    $path = "properties/{$propertyId}/front.jpg";
    Storage::disk('local')->put($path, 'jpeg-bytes');

    DB::table('property_media')->insert([
        'id' => 'media-1', 'property_id' => $propertyId, 'disk' => 'local', 'path' => $path,
        'is_cover' => true, 'sort_order' => 0,
    ]);
}

test('a photo url opens the photo until it expires', function () {
    $id = listing();
    photo($id);

    $url = $this->getJson("/api/v1/properties/{$id}")->json('data.photos.0.url');

    expect($this->get($url)->assertOk()->streamedContent())->toBe('jpeg-bytes');
    $this->get(str_replace('front.jpg', 'other.jpg', $url))->assertForbidden();

    $this->travel(PropertyMedia::URL_TTL_MINUTES + 1)->minutes();
    $this->get($url)->assertForbidden();
});

test('a draft photo is only reachable through the owner url', function () {
    $id = listing(['status' => 'draft', 'published_at' => null]);
    photo($id);

    $url = $this->withToken(tokenFor('owner-1'))->getJson("/api/v1/properties/my-properties/{$id}")
        ->json('data.photos.0.url');

    $this->get($url)->assertOk();
    $this->get(strtok($url, '?'))->assertForbidden();
});

test('anyone can open a published property with its rating, owner card and map pin', function () {
    $id = listing();
    photo($id);
    DB::table('amenities')->insert(['id' => 'amenity-1', 'code' => 'parking']);
    DB::table('property_amenities')->insert(['property_id' => $id, 'amenity_id' => 'amenity-1']);
    DB::table('property_ratings')->insert([
        ['id' => 'rating-1', 'property_id' => $id, 'score' => 4],
        ['id' => 'rating-2', 'property_id' => $id, 'score' => 5],
    ]);

    $response = $this->getJson("/api/v1/properties/{$id}")->assertOk();

    $response->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.price', 450)
        ->assertJsonPath('data.average_rating', 4.5)
        ->assertJsonPath('data.ratings_count', 2)
        ->assertJsonPath('data.latitude', 31.9038)
        ->assertJsonPath('data.longitude', 35.2034)
        ->assertJsonPath('data.features', ['parking'])
        ->assertJsonPath('data.photos.0.id', 'media-1')
        ->assertJsonPath('data.owner', [
            'id' => 'owner-1', 'name' => 'Amal', 'is_verified' => true, 'member_since' => '2026-01-15',
        ])
        ->assertJsonMissingPath('data.ownership_documents')
        ->assertJsonMissingPath('data.owner.email');
});

test('an unrated property has no average yet', function () {
    $this->getJson('/api/v1/properties/'.listing())
        ->assertJsonPath('data.average_rating', null)
        ->assertJsonPath('data.ratings_count', 0);
});

test('a property that is not published is not found', function (string $status) {
    $this->getJson('/api/v1/properties/'.listing(['status' => $status]))->assertNotFound();
})->with(['draft', 'pending_verification', 'rejected', 'under_contract']);

test('a deleted or unknown property is not found', function () {
    $this->getJson('/api/v1/properties/'.listing(['deleted_at' => now()]))->assertNotFound();
    $this->getJson('/api/v1/properties/'.Str::uuid())->assertNotFound();
});

function tokenFor(string $userId): string
{
    return app(JwtService::class)->issueAccessToken(User::find($userId));
}

test('the owner opens their own listing whatever its status', function (string $status) {
    $id = listing(['status' => $status, 'published_at' => null]);
    DB::table('ownership_documents')->insert([
        'id' => 'ownership-1', 'property_id' => $id, 'type' => 'title_deed', 'status' => 'pending',
    ]);

    $this->withToken(tokenFor('owner-1'))->getJson("/api/v1/properties/my-properties/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.latitude', 31.9038)
        ->assertJsonPath('data.longitude', 35.2034)
        ->assertJsonPath('data.ownership_documents', [
            ['id' => 'ownership-1', 'type' => 'title_deed', 'status' => 'pending', 'rejection_reason' => null],
        ]);
})->with(['draft', 'pending_verification', 'rejected', 'published']);

test('the owner sees why a document was rejected', function () {
    $id = listing(['status' => 'rejected', 'published_at' => null]);
    DB::table('ownership_documents')->insert([
        'id' => 'ownership-1', 'property_id' => $id, 'type' => 'title_deed', 'status' => 'rejected',
        'rejection_reason' => 'The deed is unreadable.',
    ]);

    $this->withToken(tokenFor('owner-1'))->getJson("/api/v1/properties/my-properties/{$id}")
        ->assertJsonPath('data.ownership_documents.0.status', 'rejected')
        ->assertJsonPath('data.ownership_documents.0.rejection_reason', 'The deed is unreadable.');
});

test('someone else cannot open the owner view of a listing', function () {
    $this->withToken(tokenFor('other-1'))
        ->getJson('/api/v1/properties/my-properties/'.listing(['status' => 'draft']))
        ->assertNotFound();
});

test('the owner view of a deleted listing is not found', function () {
    $this->withToken(tokenFor('owner-1'))
        ->getJson('/api/v1/properties/my-properties/'.listing(['deleted_at' => now()]))
        ->assertNotFound();
});

test('the owner view requires a signed-in user', function () {
    $this->getJson('/api/v1/properties/my-properties/'.listing())->assertUnauthorized();
});

test('the owner routes are not mistaken for a property id', function () {
    $this->getJson('/api/v1/properties/my-properties')->assertUnauthorized();
    $this->getJson('/api/v1/properties/my-requests')->assertUnauthorized();
    $this->getJson('/api/v1/properties/incoming-requests')->assertUnauthorized();
});
