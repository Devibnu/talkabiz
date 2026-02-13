<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade untuk InboxService
 * 
 * @method static array prosesPesanMasuk(array $data)
 * @method static array tandaiSudahDibaca(int $percakapanId, int $penggunaId)
 * @method static array ambilPercakapan(int $percakapanId, int $penggunaId)
 * @method static array lepasPercakapan(int $percakapanId, int $penggunaId)
 * 
 * @see \App\Services\InboxService
 */
class Inbox extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'inbox';
    }
}
