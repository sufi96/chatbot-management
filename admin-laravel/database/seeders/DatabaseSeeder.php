<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\System;
use App\Models\BotProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Super Admin (Full platform access)
        $superAdmin = User::updateOrCreate(
            ['email' => 'admin@chatbothub.com'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'global_role' => 'super_admin',
            ]
        );

        // 2. Create System Admin
        $manager = User::updateOrCreate(
            ['email' => 'manager@chatbothub.com'],
            [
                'name' => 'John (System Manager)',
                'password' => Hash::make('password'),
                'global_role' => 'user',
            ]
        );

        // 3. Create Editor
        $editor = User::updateOrCreate(
            ['email' => 'editor@chatbothub.com'],
            [
                'name' => 'Sarah (Bot Editor)',
                'password' => Hash::make('password'),
                'global_role' => 'user',
            ]
        );

        // 4. Create Viewer
        $viewer = User::updateOrCreate(
            ['email' => 'viewer@chatbothub.com'],
            [
                'name' => 'Alex (Support Viewer)',
                'password' => Hash::make('password'),
                'global_role' => 'user',
            ]
        );

        // 5. Create Systems / Workspaces
        $sys1 = System::updateOrCreate(
            ['id' => 'sys_default_01'],
            [
                'name' => 'Corporate Customer Portal',
                'description' => 'Main corporate web portal and internal employee service desk.',
                'allowed_origins' => '*',
            ]
        );

        $sys2 = System::updateOrCreate(
            ['id' => 'sys_ecommerce_02'],
            [
                'name' => 'E-Commerce Online Store',
                'description' => 'Direct-to-consumer store with product checkout and FAQs.',
                'allowed_origins' => 'http://localhost:8080, https://shop.example.com',
            ]
        );

        // 6. Assign Users to Systems with Roles (RBAC)
        $sys1->users()->syncWithoutDetaching([
            $manager->id => ['role' => 'system_admin'],
            $editor->id => ['role' => 'editor'],
        ]);

        $sys2->users()->syncWithoutDetaching([
            $manager->id => ['role' => 'system_admin'],
            $viewer->id => ['role' => 'viewer'],
        ]);

        // 7. Create Demo Bot Profiles
        BotProfile::updateOrCreate(
            ['id' => 'bot_demo_default'],
            [
                'system_id' => $sys1->id,
                'name' => 'Corporate Support Assistant',
                'system_prompt' => 'You are a professional customer support AI assistant. Answer inquiries clearly, politely, and succinctly.',
                'provider_type' => 'ollama',
                'base_url' => 'http://localhost:11434/v1',
                'api_key' => null,
                'model_name' => 'llama3.2',
                'temperature' => 0.7,
                'max_tokens' => 1024,
                'widget_title' => 'Support Assistant',
                'widget_greeting' => 'Hi there! 👋 How can we help you today?',
                'widget_primary_color' => '#0d6efd',
                'widget_position' => 'bottom-right',
                'is_active' => true,
            ]
        );

        BotProfile::updateOrCreate(
            ['id' => 'bot_sales_02'],
            [
                'system_id' => $sys2->id,
                'name' => 'Sales & Product Concierge',
                'system_prompt' => 'You are an enthusiastic sales advisor. Help users discover products, check specs, and recommend the best options.',
                'provider_type' => 'custom',
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'sk-demo-key',
                'model_name' => 'gpt-4o-mini',
                'temperature' => 0.8,
                'max_tokens' => 1500,
                'widget_title' => 'Shop Concierge',
                'widget_greeting' => 'Looking for recommendations or have questions about our catalog? Ask me anything! 🛍️',
                'widget_primary_color' => '#198754',
                'widget_position' => 'bottom-right',
                'is_active' => true,
            ]
        );
    }
}
