<?php

use App\Mcp\Servers\LibraryDeskServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('library-desk', LibraryDeskServer::class);
