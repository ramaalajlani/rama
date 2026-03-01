<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $q = Activity::query()
            ->with(['causer', 'subject'])
            ->latest('id');

        if ($request->filled('user')) {
            $user = trim((string)$request->get('user'));
            $q->whereHas('causer', function ($qq) use ($user) {
                $qq->where('username', 'like', "%{$user}%")
                  ->orWhere('name', 'like', "%{$user}%")
                  ->orWhere('id', $user);
            });
        }

        if ($request->filled('event')) {
            $ev = trim((string)$request->get('event'));
            $q->where('event', 'like', "%{$ev}%");
        }

        if ($request->filled('log_name')) {
            $ln = trim((string)$request->get('log_name'));
            $q->where('log_name', 'like', "%{$ln}%");
        }

        if ($request->filled('subject')) {
            $subject = trim((string)$request->get('subject'));
            $q->where('subject_type', 'like', "%{$subject}%");
        }

        if ($request->filled('subject_id')) {
            $q->where('subject_id', (int)$request->get('subject_id'));
        }

        if ($request->filled('ip')) {
            $ip = trim((string)$request->get('ip'));
            $q->where(function ($qq) use ($ip) {
                $qq->where('properties->ip', 'like', "%{$ip}%")
                   ->orWhere('properties->ip_address', 'like', "%{$ip}%");
            });
        }

        if ($request->filled('url')) {
            $url = trim((string)$request->get('url'));
            $q->where('properties->url', 'like', "%{$url}%");
        }

        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->date('to'));
        }

        if ($request->filled('search')) {
            $s = trim((string)$request->get('search'));
            $q->where('description', 'like', "%{$s}%");
        }

        $perPage = min(max((int)$request->get('per_page', 200), 1), 1000);
        $p = $q->paginate($perPage);

        $items = $p->getCollection()->map(function (Activity $a) {
            $props = $a->properties ? $a->properties->toArray() : [];

            return [
                'id'         => $a->id,
                'time'       => optional($a->created_at)->format('Y-m-d H:i:s'),
                'log_name'   => $a->log_name,
                'user'       => $a->causer?->username ?? $a->causer?->name ?? 'System',
                'event'      => $a->event,
                'description'=> $a->description,
                'subject'    => $a->subject_type ? class_basename($a->subject_type) : null,
                'subject_id' => $a->subject_id,
                'ip'         => $props['ip'] ?? ($props['ip_address'] ?? null),
                'url'        => $props['url'] ?? null,
                'batch_uuid' => $a->batch_uuid,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $items,
                'meta'  => [
                    'current_page' => $p->currentPage(),
                    'per_page'     => $p->perPage(),
                    'total'        => $p->total(),
                ],
            ],
        ]);
    }
}