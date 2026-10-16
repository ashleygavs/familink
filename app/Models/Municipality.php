<?php

namespace App\Models;

use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $province
 */
#[Fillable(['name', 'province'])]
class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
    use HasFactory;

    /**
     * The short code used in staff account names: the first three letters of a one-word name (Bacolor is BAC),
     * or the initials of each word completed from the last word (San Luis is SLU).
     */
    public function code(): string
    {
        $words = preg_split('/\s+/', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) <= 1) {
            return Str::upper(Str::substr($words[0] ?? '', 0, 3));
        }

        $code = implode('', array_map(fn (string $word): string => Str::substr($word, 0, 1), $words));
        $last = $words[count($words) - 1];

        for ($index = 1; Str::length($code) < 3 && $index < Str::length($last); $index++) {
            $code .= Str::substr($last, $index, 1);
        }

        return Str::upper($code);
    }

    /**
     * @return HasMany<Barangay, $this>
     */
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class);
    }

    /**
     * Admins assigned to this municipality.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
