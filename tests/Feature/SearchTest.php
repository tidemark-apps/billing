<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_contains_search_treats_wildcards_literally(): void
    {
        Client::factory()->create(['name' => '100% Hosting']);
        Client::factory()->create(['name' => 'Under_score Ltd']);
        Client::factory()->create(['name' => 'Plain Co']);

        $this->assertSame(['100% Hosting'], Client::whereContains('name', '0%')->pluck('name')->all());
        $this->assertSame(['Under_score Ltd'], Client::whereContains('name', 'r_s')->pluck('name')->all());
        $this->assertSame(['Plain Co'], Client::whereContains('name', 'PLAIN')->pluck('name')->all());
        $this->assertCount(0, Client::whereContains('name', '%%')->get());
    }
}
