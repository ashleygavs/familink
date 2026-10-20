<?php

namespace App\Http\Controllers;

use App\Actions\Relief\CheckFamilyData;
use App\Enums\RoleName;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FamilyDataCheckController extends Controller
{
    /**
     * Family links that cannot be right, for the residents the viewer may see. Admins can open each resident to
     * correct the record. Stakeholders only see the list.
     */
    public function __invoke(Request $request, CheckFamilyData $check): Response
    {
        $user = $request->user();

        return Inertia::render('reports/data-check', [
            ...$check->handle($user),
            'canFix' => ! $user->hasRole(RoleName::Stakeholder),
        ]);
    }
}
