<?php

namespace App\Enums;

enum LibraryPermission: string
{
    case ViewBooks = 'books.view';
    case CreateBooks = 'books.create';
    case UpdateBooks = 'books.update';
    case DeleteBooks = 'books.delete';
    case ViewLoans = 'loans.view';
    case ViewOwnLoans = 'loans.view-own';
    case ManageLoans = 'loans.manage';
    case ViewMembers = 'members.view';
    case ManageMembers = 'members.manage';
    case ViewReports = 'reports.view';
    case ManageAccess = 'access.manage';
}
