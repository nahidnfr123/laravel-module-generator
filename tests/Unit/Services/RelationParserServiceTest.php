<?php

namespace NahidFerdous\LaravelModuleGenerator\Tests\Unit\Services;

use NahidFerdous\LaravelModuleGenerator\Services\RelationParserService;
use NahidFerdous\LaravelModuleGenerator\Tests\TestCase;

class RelationParserServiceTest extends TestCase
{
    private RelationParserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RelationParserService;
    }

    public function test_it_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], $this->service->parse([]));
    }

    public function test_it_skips_non_string_relation_lists(): void
    {
        $result = $this->service->parse([
            'hasMany' => ['array', 'not', 'string'],
        ]);

        $this->assertSame([], $result);
    }

    public function test_it_parses_relations_with_explicit_names(): void
    {
        $result = $this->service->parse([
            'hasMany' => 'Product:products, Review:reviews',
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(['type' => 'hasMany', 'model' => 'Product'], $result['products']);
        $this->assertSame(['type' => 'hasMany', 'model' => 'Review'], $result['reviews']);
    }

    public function test_it_generates_default_name_for_belongs_to(): void
    {
        $result = $this->service->parse([
            'belongsTo' => 'Category, User',
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('belongsTo', $result['category']['type']);
        $this->assertSame('Category', $result['category']['model']);
        $this->assertSame('belongsTo', $result['user']['type']);
        $this->assertSame('User', $result['user']['model']);
    }

    public function test_it_generates_plural_name_for_has_many(): void
    {
        $result = $this->service->parse([
            'hasMany' => 'Product, Category',
        ]);

        $this->assertArrayHasKey('products', $result);
        $this->assertSame('Product', $result['products']['model']);
        $this->assertArrayHasKey('categories', $result);
        $this->assertSame('Category', $result['categories']['model']);
    }

    public function test_it_generates_plural_name_for_belongs_to_many(): void
    {
        $result = $this->service->parse([
            'belongsToMany' => 'Role, Permission',
        ]);

        $this->assertArrayHasKey('roles', $result);
        $this->assertSame('Role', $result['roles']['model']);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertSame('Permission', $result['permissions']['model']);
    }

    public function test_it_parses_mixed_relation_types(): void
    {
        $result = $this->service->parse([
            'belongsTo' => 'User, Category',
            'hasMany' => 'Product:products',
            'belongsToMany' => 'Tag:tags',
        ]);

        $this->assertCount(4, $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('tags', $result);
    }

    public function test_it_handles_whitespace_in_definitions(): void
    {
        $result = $this->service->parse([
            'belongsTo' => '  Product : product ,  User  ',
        ]);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('product', $result);
        $this->assertSame('Product', $result['product']['model']);
        $this->assertSame('User', $result['user']['model']);
    }

    public function test_it_parses_models_with_namespace_notation(): void
    {
        $result = $this->service->parse([
            'hasMany' => 'App\Models\Order:orders',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('App\Models\Order', $result['orders']['model']);
    }
}
