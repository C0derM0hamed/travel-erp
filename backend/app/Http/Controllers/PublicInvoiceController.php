<?php

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Services\OperationInvoiceService;
use Symfony\Component\HttpFoundation\Response;

class PublicInvoiceController extends Controller
{
    public function show(Operation $operation, OperationInvoiceService $invoices): Response
    {
        $operation = Operation::withoutGlobalScopes()->findOrFail($operation->id);

        return $invoices->downloadPdf($operation, true);
    }
}
