<?php
declare(strict_types=1);

namespace Crustum\Mongo\Datasource\Paging;

use Cake\Datasource\Paging\NumericPaginator;

/**
 * Paginator for MongoDB repositories and queries.
 *
 * `NumericPaginator::paginate()` already provides the full flow (extract data,
 * build query, fetch items, count via `QueryInterface::count()`, page-bounds
 * check). Mongo cursors are not rewindable, so the total count is delegated to
 * `QueryInterface::count()` (the ODM `SelectQuery` implements it as a
 * `countDocuments` call) and the fetched page is counted directly. This subclass
 * is the Mongo entry point so the application can register it explicitly.
 */
class MongoPaginator extends NumericPaginator
{
}
