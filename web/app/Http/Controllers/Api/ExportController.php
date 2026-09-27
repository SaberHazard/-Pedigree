<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marriage;
use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\Tree\GedcomExporter;
use App\Services\Tree\TreeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * خروجی GEDCOM از بخشی از درخت یا کل پایگاه (فقط مدیر)
 */
class ExportController extends Controller
{
    public function gedcom(Request $request, TreeService $tree, GedcomExporter $exporter, AuditLogger $audit): Response
    {
        $data = $request->validate([
            'mode' => ['required', 'in:descendants,ancestors,hourglass,all'],
            'person' => ['required_unless:mode,all', 'nullable', 'uuid'],
            'depth' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        if ($data['mode'] === 'all') {
            abort_unless($request->user()->isAdmin(), 403, 'خروجی کامل فقط برای مدیر مجاز است.');
            $persons = Person::all();
            $marriages = Marriage::all();
            $title = 'کل شجره‌نامه';
        } else {
            $root = Person::findOrFail($data['person']);
            Gate::authorize('view', $root);
            $depth = (int) ($data['depth'] ?? 10);
            $result = match ($data['mode']) {
                'descendants' => $tree->descendants($root, $depth),
                'ancestors' => $tree->ancestors($root, $depth),
                'hourglass' => $tree->hourglass($root, $depth, $depth),
            };
            $ids = array_column($result['persons'], 'id');
            $persons = Person::whereIn('id', $ids)->get();
            $marriages = Marriage::whereIn('id', array_column($result['marriages'], 'id'))->get();
            $title = 'شجره‌نامه '.$root->fullName();
        }

        $audit->log('export.gedcom', null, ['mode' => $data['mode'], 'count' => $persons->count()], $request->user());
        $content = $exporter->export($persons, $marriages, $title);

        return response($content, 200, [
            'Content-Type' => 'text/vnd.familysearch.gedcom; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pedigree-'.date('Ymd').'.ged"',
        ]);
    }
}
