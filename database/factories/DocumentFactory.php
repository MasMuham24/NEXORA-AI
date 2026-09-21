<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'title' => fake()->sentence(3),
            'original_filename' => fake()->word().'.txt',
            'mime_type' => 'text/plain',
            'file_size' => fake()->numberBetween(100, 100000),
            'content' => fake()->paragraph(),
        ];
    }
}