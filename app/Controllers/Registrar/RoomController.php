<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Room;
use App\Services\CatalogService;

/**
 * Rooms (docs/13). Deactivated, never deleted.
 */
final class RoomController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('registrar/rooms/index', ['title' => 'Rooms', 'rooms' => Room::all()]);
    }

    public function create(Request $request): void
    {
        $this->appView('registrar/rooms/form', ['title' => 'New room', 'room' => null, 'types' => Room::TYPES]);
    }

    public function edit(Request $request): void
    {
        $room = Room::find((int) $request->param('id'));
        if ($room === null) {
            throw HttpException::notFound('That room was not found.');
        }
        $this->appView('registrar/rooms/form', ['title' => 'Edit ' . $room['code'], 'room' => $room, 'types' => Room::TYPES]);
    }

    public function save(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/registrar/rooms/create' : "/registrar/rooms/{$id}/edit";

        try {
            CatalogService::saveRoom(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Room created.' : 'Room saved.', '/registrar/rooms');
    }
}
