<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait HasHybridFilters
{
    /**
     * Konfigurasi filter yang diizinkan.
     * Override di controller yang menggunakan trait ini.
     *
     * @return array<string, array>
     *                              key = nama field filter
     *                              value = [
     *                              'type' => 'text|select|date|daterange|month',
     *                              'column' => 'kolom_database', (opsional, default = key)
     *                              'operator' => '=|like|between|whereIn|whereHas', (opsional)
     *                              'relation' => 'nama_relasi', (untuk whereHas)
     *                              'relationColumn' => 'kolom_di_relasi', (untuk whereHas)
     *                              'transform' => fn($value) => $transformed, (opsional)
     *                              'default' => 'default_value', (opsional)
     *                              ]
     */
    abstract protected function getFilterConfig(): array;

    /**
     * Terapkan filter ke query builder.
     */
    protected function applyFilters(Builder $query, Request $request, ?array $config = null): Builder
    {
        $config = $config ?? $this->getFilterConfig();

        foreach ($config as $key => $cfg) {
            $value = $request->input($key);

            // Skip jika tidak diisi (kecuali ada default)
            if ($value === '' || $value === null) {
                if (isset($cfg['default'])) {
                    $value = $cfg['default'];
                } else {
                    continue;
                }
            }

            $column = $cfg['column'] ?? $key;
            $operator = $cfg['operator'] ?? '=';
            $type = $cfg['type'] ?? 'text';

            // Transform value jika ada
            if (isset($cfg['transform']) && is_callable($cfg['transform'])) {
                $value = $cfg['transform']($value, $request);
            }

            // Handle berdasarkan operator
            match ($operator) {
                'like' => $query->where($column, 'LIKE', "%{$value}%"),
                'between' => $query->whereBetween($column, (array) $value),
                'whereIn' => $query->whereIn($column, (array) $value),
                'whereHas' => $this->applyWhereHas($query, $cfg, $value),
                'whereDate' => $query->whereDate($column, $value),
                'whereBetweenDate' => $query->whereBetween($column, $value),
                default => $query->where($column, $operator, $value),
            };
        }

        return $query;
    }

    /**
     * Terapkan whereHas untuk filter relasi.
     */
    protected function applyWhereHas(Builder $query, array $cfg, mixed $value): void
    {
        $relation = $cfg['relation'] ?? '';
        $relationColumn = $cfg['relationColumn'] ?? 'id';
        $operator = $cfg['relationOperator'] ?? '=';

        if (! $relation) {
            return;
        }

        $query->whereHas($relation, function ($q) use ($relationColumn, $operator, $value) {
            $q->where($relationColumn, $operator, $value);
        });
    }

    /**
     * Build pagination dengan query string filter.
     */
    protected function paginateWithFilters(Builder $query, Request $request, int $perPage = 15): LengthAwarePaginator
    {
        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Helper: filter config untuk search text (nama, nis, dll).
     */
    protected function textSearchConfig(string $key, string $column, ?string $default = null): array
    {
        return [
            $key => [
                'type' => 'text',
                'column' => $column,
                'operator' => 'like',
                'default' => $default,
            ],
        ];
    }

    /**
     * Helper: filter config untuk select dropdown.
     */
    protected function selectConfig(string $key, string $column, ?string $default = null, string $operator = '='): array
    {
        return [
            $key => [
                'type' => 'select',
                'column' => $column,
                'operator' => $operator,
                'default' => $default,
            ],
        ];
    }

    /**
     * Helper: filter config untuk date range.
     */
    protected function dateRangeConfig(string $keyFrom, string $keyTo, string $column, ?array $default = null): array
    {
        return [
            $keyFrom => [
                'type' => 'date',
                'column' => $column,
                'operator' => 'whereBetweenDate',
                'pairedWith' => $keyTo,
                'default' => $default[0] ?? null,
            ],
            $keyTo => [
                'type' => 'date',
                'column' => $column,
                'operator' => 'whereBetweenDate',
                'pairedWith' => $keyFrom,
                'default' => $default[1] ?? null,
            ],
        ];
    }

    /**
     * Helper: filter config untuk relasi (whereHas).
     */
    protected function relationConfig(string $key, string $relation, string $relationColumn = 'id', string $operator = '='): array
    {
        return [
            $key => [
                'type' => 'select',
                'operator' => 'whereHas',
                'relation' => $relation,
                'relationColumn' => $relationColumn,
                'relationOperator' => $operator,
            ],
        ];
    }

    /**
     * Helper: filter config untuk month picker (Y-m format).
     */
    protected function monthConfig(string $key, string $column, ?string $default = null): array
    {
        return [
            $key => [
                'type' => 'month',
                'column' => $column,
                'operator' => 'whereMonthYear',
                'transform' => fn ($value) => $value ? Carbon::parse($value.'-01') : null,
                'default' => $default,
            ],
        ];
    }
}
