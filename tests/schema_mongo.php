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
            'author_id' => ['bsonType' => ['objectId', 'null']],
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
    'articles_embed' => [
        'fields' => [
            'author_id' => ['bsonType' => ['objectId', 'null']],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
            '_translations' => ['bsonType' => 'object'],
        ],
        'indexes' => [
            'articles_embed_published' => ['key' => ['published' => 1]],
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
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'users_username' => ['key' => ['username' => 1]],
        ],
    ],
    'posts' => [
        'fields' => [
            'author_id' => ['bsonType' => 'objectId'],
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
            'created' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'tags_name' => ['key' => ['name' => 1]],
        ],
    ],
    'comments' => [
        'fields' => [
            'article_id' => ['bsonType' => 'objectId'],
            'user_id' => ['bsonType' => 'objectId'],
            'comment' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'comments_article_id' => ['key' => ['article_id' => 1]],
            'comments_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'categories' => [
        'fields' => [
            'parent_id' => ['bsonType' => ['string', 'int']],
            'name' => ['bsonType' => 'string'],
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'categories_parent_id' => ['key' => ['parent_id' => 1]],
            'categories_name' => ['key' => ['name' => 1]],
        ],
    ],
    'articles_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'objectId'],
            'tag_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'articles_tags_article_id' => ['key' => ['article_id' => 1]],
            'articles_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'articles_tags_binding_keys' => [
        'fields' => [
            'article_id' => ['bsonType' => 'objectId'],
            'tagname' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'articles_tags_binding_keys_article_id' => ['key' => ['article_id' => 1]],
            'articles_tags_binding_keys_tagname' => ['key' => ['tagname' => 1]],
        ],
    ],
    'authors_tags' => [
        'fields' => [
            'author_id' => ['bsonType' => 'objectId'],
            'tag_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'authors_tags_author_id' => ['key' => ['author_id' => 1]],
            'authors_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'special_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'objectId'],
            'tag_id' => ['bsonType' => 'objectId'],
            'author_id' => ['bsonType' => ['string', 'null']],
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
            'tag_id' => ['bsonType' => 'objectId'],
            'priority' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'featured_tags_tag_id' => ['key' => ['tag_id' => 1]],
        ],
    ],
    'profiles' => [
        'fields' => [
            'user_id' => ['bsonType' => 'objectId'],
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
            'user_id' => ['bsonType' => ['objectId', 'null']],
        ],
        'indexes' => [
            'counter_cache_comments_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_posts' => [
        'fields' => [
            'title' => ['bsonType' => 'string'],
            'user_id' => ['bsonType' => ['objectId', 'null']],
            'category_id' => ['bsonType' => ['objectId', 'null']],
            'published' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'counter_cache_posts_user_id' => ['key' => ['user_id' => 1]],
        ],
    ],
    'counter_cache_user_category_posts' => [
        'fields' => [
            'category_id' => ['bsonType' => ['objectId', 'null']],
            'user_id' => ['bsonType' => ['objectId', 'null']],
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
            'ancestors' => ['bsonType' => 'array'],
            'lft' => ['bsonType' => 'int'],
            'rght' => ['bsonType' => 'int'],
            'depth' => ['bsonType' => 'int'],
            'sort' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'number_trees_parent_id' => ['key' => ['parent_id' => 1]],
            'number_trees_ancestors' => ['key' => ['ancestors' => 1]],
        ],
    ],
    'number_trees_articles' => [
        'fields' => [
            'number_tree_id' => ['bsonType' => 'objectId'],
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
            'ancestors' => ['bsonType' => 'array'],
            'lft' => ['bsonType' => 'int'],
            'rght' => ['bsonType' => 'int'],
            'sort' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'menu_link_trees_menu' => ['key' => ['menu' => 1]],
            'menu_link_trees_ancestors' => ['key' => ['ancestors' => 1]],
        ],
    ],
    'site_articles' => [
        'fields' => [
            'author_id' => ['bsonType' => ['objectId', 'null']],
            'site_id' => ['bsonType' => ['objectId', 'null']],
            'title' => ['bsonType' => 'string'],
            'body' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'site_articles_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'site_articles_tags' => [
        'fields' => [
            'article_id' => ['bsonType' => 'objectId'],
            'tag_id' => ['bsonType' => 'objectId'],
            'site_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'site_articles_tags_article_id' => ['key' => ['article_id' => 1]],
        ],
    ],
    'site_authors' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'site_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'site_authors_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'site_tags' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'site_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'site_tags_site_id' => ['key' => ['site_id' => 1]],
        ],
    ],
    'articles_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            '_shadow_id' => ['bsonType' => 'objectId'],
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
            '_shadow_id' => ['bsonType' => 'objectId'],
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
            '_shadow_id' => ['bsonType' => 'objectId'],
            'comment' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'comments_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'tags_shadow_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            '_shadow_id' => ['bsonType' => 'objectId'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'tags_shadow_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'authors_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            '_shadow_id' => ['bsonType' => 'objectId'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'authors_translations_id' => ['key' => ['id' => 1]],
        ],
    ],
    'special_tags_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            '_shadow_id' => ['bsonType' => 'objectId'],
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
            'foreign_key' => ['bsonType' => 'objectId'],
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
            'article_id' => ['bsonType' => 'objectId'],
            'user_id' => ['bsonType' => 'objectId'],
            'comment' => ['bsonType' => 'string'],
            'published' => ['bsonType' => 'string'],
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'test_plugin_comments_article_id' => ['key' => ['article_id' => 1]],
        ],
    ],
    'auth_users' => [
        'fields' => [
            'username' => ['bsonType' => 'string'],
            'password' => ['bsonType' => 'string'],
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
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
    'sections_translations' => [
        'fields' => [
            'locale' => ['bsonType' => 'string'],
            '_shadow_id' => ['bsonType' => 'objectId'],
            'title' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
    'sections_members' => [
        'fields' => [
            'section_id' => ['bsonType' => 'objectId'],
            'member_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'sections_members_section_id' => ['key' => ['section_id' => 1]],
        ],
    ],
    'polymorphic_tagged' => [
        'fields' => [
            'tag_id' => ['bsonType' => 'objectId'],
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
            'product_id' => ['bsonType' => 'objectId'],
        ],
        'indexes' => [
            'orders_product_id' => ['key' => ['product_id' => 1]],
        ],
    ],
    'attachments' => [
        'fields' => [
            'comment_id' => ['bsonType' => 'objectId'],
            'attachment' => ['bsonType' => 'string'],
            'created' => ['bsonType' => 'date'],
            'updated' => ['bsonType' => 'date'],
        ],
        'indexes' => [
            'attachments_comment_id' => ['key' => ['comment_id' => 1]],
        ],
    ],
    'unique_authors' => [
        'fields' => [
            'first_author_id' => ['bsonType' => ['string', 'null']],
            'second_author_id' => ['bsonType' => ['string', 'null']],
        ],
        'indexes' => [
            'unique_authors_first_author_id' => ['key' => ['first_author_id' => 1]],
        ],
    ],
    'nullable_authors' => [
        'fields' => [
            'author_id' => ['bsonType' => ['string', 'null']],
        ],
        'indexes' => [],
    ],
    'uuid_items' => [
        'fields' => [
            '_id' => ['bsonType' => 'string'],
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
            '_id' => ['bsonType' => 'string'],
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
            '_id' => ['bsonType' => 'string'],
            'id' => ['bsonType' => 'string'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
    'audits' => [
        'fields' => [
            'foreign_key' => ['bsonType' => 'objectId'],
            'model' => ['bsonType' => 'string'],
            'note' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'audits_foreign_key' => ['key' => ['foreign_key' => 1]],
        ],
    ],
    'bridge_posts' => [
        'fields' => [
            'order_id' => ['bsonType' => ['int', 'null']],
            'title' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
    'bridge_tags' => [
        'fields' => [
            'name' => ['bsonType' => 'string'],
            'bridge_order_ids' => ['bsonType' => 'array'],
        ],
        'indexes' => [],
    ],
    'bridge_profiles' => [
        'fields' => [
            'order_id' => ['bsonType' => ['int', 'null']],
            'bio' => ['bsonType' => 'string'],
        ],
        'indexes' => [],
    ],
    'int_primary_items' => [
        'fields' => [
            'id' => ['bsonType' => 'int'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'int_primary_items_id' => ['key' => ['id' => 1], 'options' => ['unique' => true]],
        ],
    ],
    'uuid_primary_items' => [
        'fields' => [
            'id' => ['bsonType' => 'string'],
            'name' => ['bsonType' => 'string'],
        ],
        'indexes' => [
            'uuid_primary_items_id' => ['key' => ['id' => 1], 'options' => ['unique' => true]],
        ],
    ],
    'cake_increment_ids' => [
        'fields' => [
            'value' => ['bsonType' => 'int'],
        ],
        'indexes' => [
            'cake_increment_ids_key' => ['key' => ['_id' => 1], 'options' => ['unique' => true]],
        ],
    ],
];
