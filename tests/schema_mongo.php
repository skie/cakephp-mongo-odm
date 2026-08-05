<?php
declare(strict_types=1);

/**
 * Test database schema for the Mongo connection.
 *
 * Keyed by collection name. Loaded by
 * `Crustum\Mongo\TestSuite\Fixture\SchemaGenerator` in `tests/bootstrap.php`.
 *
 * Covers the fixture collections (ported from cake60) that ODM tests filter
 * and index on. `fields` are bsonType maps inferred from the fixture seed
 * data; nullable fields use the `['type', 'null']` array form.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'test_users' => [
        'fields' => [
            'username' => ['bsonType' => 'string'],
            'email' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'test_users_username' => ['key' => ['username' => 1]],
        ],
    ],
    'articles' => [
        'fields' => [
            'author_id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'articles_published' => ['key' => ['published' => 1]],
            'articles_title' => ['key' => ['title' => 1]],
            'articles_author_id' => ['key' => ['author_id' => 1]],
        ],
    ],
    'authors' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'authors_name' => ['key' => ['name' => 1]],
        ],
    ],
    'users' => [
        'fields' => [
            'username' => ['bsonType' => 'string'],
            'password' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'users_username' => ['key' => ['username' => 1]],
        ],
    ],
    'posts' => [
        'fields' => [
            'author_id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'posts_published' => ['key' => ['published' => 1]],
            'posts_title' => ['key' => ['title' => 1]],
            'posts_author_id' => ['key' => ['author_id' => 1]],
        ],
    ],
    'tags' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'description' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'tags_name' => ['key' => ['name' => 1]],
        ],
    ],
    'comments' => [
        'fields' => [
            'article_id' => ['bsonType' => 'int'],
            'user_id' => ['bsonType' => 'int'],
            'comment' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'comments_article_id' => ['key' => ['article_id' => 1]],
            'comments_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'categories' => [
        'fields' => [
            'parent_id' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'categories_parent_id' => ['key' => ['parent_id' => 1]],
            'categories_name' => ['key' => ['name' => 1]],
        ],
    ],
    'articles_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'int'],
            'tag_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'articles_tags_article_id' => ['key' => ['article_id' => 1]],
            'articles_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'authors_tags' => [
        'fields' => [
            'author_id' => ['bsonType' => 'int'],
            'tag_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'authors_tags_author_id' => ['key' => ['author_id' => 1]],
            'authors_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'special_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'int'],
            'tag_id' => ['bsonType' => 'int'],
            'author_id' => ['bsonType' => ['int', 'null']],
            'extra_info' => ['bsonType' => 'string'],
            'highlighted' => ['bsonType' => 'bool'],
            'highlighted_time' => ['bsonType' => ['string', 'null']],
        ],
        'indexes' => [
            'special_tags_article_id' => ['key' => ['article_id' => 1]],
            'special_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'featured_tags' => [
        'fields' => [
            'tag_id' => ['bsonType' => 'int'],
            'priority' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'featured_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'profiles' => [
        'fields' => [
            'user_id' => ['bsonType' => 'int'],
            'first_name' => ['bsonType' => 'string'],
            'last_name' => ['bsonType' => 'string'],
            'is_active' => ['bsonType' => 'bool'],
        ],
        'indexes' => [
            'profiles_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_categories' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'post_count' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_categories_name' => ['key' => ['name' => 1]],
        ],
    ],
    'counter_cache_comments' => [
        'fields' => [
            'title' => ['bsonType' => 'string'],
            'user_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_comments_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_posts' => [
        'fields' => [
            'title' => ['bsonType' => 'string'],
            'user_id' => ['bsonType' => 'int'],
            'category_id' => ['bsonType' => 'int'],
            'published' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_posts_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_user_category_posts' => [
        'fields' => [
            'category_id' => ['bsonType' => 'int'],
            'user_id' => ['bsonType' => 'int'],
            'post_count' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_user_category_posts_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_users' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'post_count' => ['bsonType' => 'int'],
            'comment_count' => ['bsonType' => 'int'],
            'posts_published' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_users_name' => ['key' => ['name' => 1]],
        ],
    ],
    'number_trees' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'parent_id' => ['bsonType' => ['string', 'null']],
            'lft' => ['bsonType' => 'string'],
            'rght' => ['bsonType' => 'string'],
            'depth' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'number_trees_parent_id' => ['key' => ['parent_id' => 1]],
        ],
    ],
    'number_trees_articles' => [
        'fields' => [
            'number_tree_id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'number_trees_articles_number_tree_id' => ['key' => ['number_tree_id' => 1]],
        ],
    ],
    'menu_link_trees' => [
        'fields' => [
            'menu' => ['bsonType' => 'string'],
            'title' => ['bsonType' => 'string'],
            'url' => ['bsonType' => 'string'],
            'parent_id' => ['bsonType' => ['string', 'null']],
            'lft' => ['bsonType' => 'string'],
            'rght' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'menu_link_trees_menu' => ['key' => ['menu' => 1]],
        ],
    ],
    'site_articles' => [
        'fields' => [
            'author_id' => ['bsonType' => 'int'],
            'site_id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'site_articles_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'site_articles_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'int'],
            'tag_id' => ['bsonType' => 'int'],
            'site_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'site_articles_tags_article_id' => ['key' => ['article_id' => 1]],
        ],
    ],
    'site_authors' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'site_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'site_authors_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'site_tags' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'site_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'site_tags_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'articles_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'articles_translations_id' => ['key' => ['id' => 1]],
            'articles_translations_locale' => ['key' => ['locale' => 1]],
        ],
    ],
    'articles_more_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'title' => ['bsonType' => 'string'],
            'subtitle' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'articles_more_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'comments_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'comment' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'comments_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'tags_shadow_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'tags_shadow_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'authors_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'authors_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'special_tags_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'int'],
            'extra_info' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'special_tags_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'tags_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'tags_translations_locale' => ['key' => ['locale' => 1]],
        ],
    ],
    'i18n' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            'model' => ['bsonType' => 'string'],
            'foreign_key' => ['bsonType' => 'int'],
            'field' => ['bsonType' => 'string'],
            'content' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'i18n_foreign_key' => ['key' => ['foreign_key' => 1]],
            'i18n_locale' => ['key' => ['locale' => 1]],
        ],
    ],
    'test_plugin_comments' => [
        'fields' => [
            'article_id' => ['bsonType' => 'int'],
            'user_id' => ['bsonType' => 'int'],
            'comment' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'test_plugin_comments_article_id' => ['key' => ['article_id' => 1]],
        ],
    ],
    'auth_users' => [
        'fields' => [
            'username' => ['bsonType' => 'string'],
            'password' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'auth_users_username' => ['key' => ['username' => 1]],
        ],
    ],
    'things' => [
        'fields' => [
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'things_title' => ['key' => ['title' => 1]],
        ],
    ],
    'products' => [
        'fields' => [
            'category' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
            'price' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'products_category' => ['key' => ['category' => 1]],
        ],
    ],
    'members' => [
        'fields' => [
            'section_count' => ['bsonType' => 'int'],
        ],
        'indexes' => [],
    ],
    'sections' => [
        'fields' => [
            'title' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
    'sections_members' => [
        'fields' => [
            'section_id' => ['bsonType' => 'int'],
            'member_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'sections_members_section_id' => ['key' => ['section_id' => 1]],
        ],
    ],
    'polymorphic_tagged' => [
        'fields' => [
            'tag_id' => ['bsonType' => 'int'],
            'foreign_key' => ['bsonType' => 'int'],
            'foreign_model' => ['bsonType' => 'string'],
            'position' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'polymorphic_tagged_foreign_key' => ['key' => ['foreign_key' => 1]],
        ],
    ],
    'orders' => [
        'fields' => [
            'product_category' => ['bsonType' => 'int'],
            'product_id' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'orders_product_id' => ['key' => ['product_id' => 1]],
        ],
    ],
    'attachments' => [
        'fields' => [
            'comment_id' => ['bsonType' => 'int'],
            'attachment' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'attachments_comment_id' => ['key' => ['comment_id' => 1]],
        ],
    ],
    'unique_authors' => [
        'fields' => [
            'first_author_id' => ['bsonType' => ['int', 'null']],
            'second_author_id' => ['bsonType' => ['int', 'null']],
        ],
        'indexes' => [
            'unique_authors_first_author_id' => ['key' => ['first_author_id' => 1]],
        ],
    ],
    'nullable_authors' => [
        'fields' => [
            'author_id' => ['bsonType' => ['int', 'null']],
        ],
        'indexes' => [],
    ],
    'uuid_items' => [
        'fields' => [
            'id' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'uuid_items_published' => ['key' => ['published' => 1]],
        ],
    ],
    'binary_uuid_items' => [
        'fields' => [
            'id' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'bool'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'binary_uuid_items_published' => ['key' => ['published' => 1]],
        ],
    ],
    'binary_uuid_tags' => [
        'fields' => [
            'id' => ['bsonType' => 'string'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
];
