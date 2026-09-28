<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * لاگ ممیزی کامل (فقط مدیر)
 */
class ActivityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'string', 'max:64'],
        ]);
        $logs = ActivityLog::with('user.person')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', addcslashes((string) $request->input('action'), '%_\\').'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->input('subject_id')))
            ->latest('id')
            ->paginate(50);

        return ActivityResource::collection($logs);
    }
}
