<?php

use App\Http\Controllers\Property\PropertyRequestController;
use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('users', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('email');
        $table->softDeletes();
    });
    Schema::create('lawyer_credentials', function (Blueprint $table) {
        $table->string('user_id')->primary();
        $table->timestamp('verified_at')->nullable();
    });
    $this->owner = new User;
    $this->owner->id = 'owner';
    $this->httpRequest = Request::create('/');
    $this->httpRequest->setUserResolver(fn () => $this->owner);
    $this->propertyRequest = new PropertyRequest(['status' => 'pending']);
    $this->propertyRequest->setRelation('property', new Property(['owner_id' => 'owner']));
});

afterEach(function () {
    DB::purge('sqlite');
});

test('owner gets every verified lawyer sorted by name without private account fields', function () {
    foreach (['Zara', 'Amal', 'Unverified', 'Ordinary', 'Deleted'] as $name) {
        DB::table('users')->insert([
            'id' => $name, 'name' => $name, 'email' => $name.'@example.test',
            'deleted_at' => $name === 'Deleted' ? now() : null,
        ]);
        if ($name !== 'Ordinary') {
            DB::table('lawyer_credentials')->insert([
                'user_id' => $name,
                'verified_at' => $name === 'Unverified' ? null : now(),
            ]);
        }
    }

    $response = app(PropertyRequestController::class)->lawyers($this->propertyRequest, $this->httpRequest);
    expect($response->getData(true))->toBe(['data' => [
        ['id' => 'Amal', 'name' => 'Amal'],
        ['id' => 'Zara', 'name' => 'Zara'],
    ]]);
});

test('lawyer list is empty when nobody is verified', function () {
    $response = app(PropertyRequestController::class)->lawyers($this->propertyRequest, $this->httpRequest);
    expect($response->getData(true))->toBe(['data' => []]);
});

test('only the request owner can view lawyer choices', function () {
    $this->owner->id = 'someone-else';
    try {
        app(PropertyRequestController::class)->lawyers($this->propertyRequest, $this->httpRequest);
        $this->fail('Expected forbidden response');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
});

test('already answered requests cannot choose a lawyer', function () {
    $this->propertyRequest->status = 'pending_lawyer_review';
    try {
        app(PropertyRequestController::class)->lawyers($this->propertyRequest, $this->httpRequest);
        $this->fail('Expected conflict response');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
});
