<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\LibraryCatalogueResource;
use App\Mcp\Tools\GetLibraryBookTool;
use App\Mcp\Tools\ListMyLibraryLoansTool;
use App\Mcp\Tools\PrepareLibraryCheckoutTool;
use App\Mcp\Tools\SearchLibraryBooksTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Library Desk')]
#[Version('1.0.0')]
#[Instructions('Provides live Library Desk catalogue data. Catalogue tools are read-only. Loan tools require an authenticated Library Desk user, and checkout preparation never changes inventory. Confirming a checkout must happen explicitly in Library Desk.')]
class LibraryDeskServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchLibraryBooksTool::class,
        GetLibraryBookTool::class,
        ListMyLibraryLoansTool::class,
        PrepareLibraryCheckoutTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        LibraryCatalogueResource::class,
    ];
}
