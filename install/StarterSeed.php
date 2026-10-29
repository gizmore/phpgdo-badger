<?php
declare(strict_types=1);
namespace GDO\Badger\install;

/** Initial community-care and reading badge groups. */
final class StarterSeed
{
    public static function groups(): array
    {
        return [
            [
                'name' => 'Caretaker', 'slug' => 'caretaker',
                'description' => 'Cleanse Togetherness',
                'icon' => 'caretaker-icon.png', 'item' => 'golden-wc-brush.png',
                'item_name' => 'Golden WC Brush',
                'achievements' => [
                    'Clean up your room.',
                    'Clean up your room every day for one full week.',
                    'Clean up the kitchen and bathroom.',
                    'Remove all trash from your room.',
                    'Remove trash from the sidewalks by your house.',
                    'Remove all trash from the whole block.',
                    'Clean up the nearest railway station.',
                    'Collect two bags of trash in nature.',
                ],
            ],
            [
                'name' => 'Bookworm', 'slug' => 'bookworm',
                'description' => 'Reading',
                'icon' => 'bookworm-icon.png', 'item' => 'page-turning-book.png',
                'item_name' => 'Page-turning Book',
                'achievements' => [
                    'Read your first news article.',
                    'Read your first book.',
                ],
            ],
        ];
    }
}
