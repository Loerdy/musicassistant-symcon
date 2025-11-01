<?php
class MusicAssistantBridge extends IPSModule
{
    private const WS_CLIENT_GUID = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const PLAYER_MODULE_GUID = '{7E1B7E6E-5C1C-4E8B-9C41-3D7C8B0D2E11}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Path', '/ws');
        // Standard: IO NICHT automatisch erzeugen, erst nach Bestätigung im Konfig-Formular
        $this->RegisterPropertyBoolean('AutoCreateIO', false);
        $this->RegisterPropertyInteger('WebSocketInstanceID', 0);
        $this->RegisterPropertyInteger('PlayersRootID', 0);

        $this->RegisterAttributeInteger('WSInstanceID', 0);
        // Keine RegisterVariable mehr – direkte Verbindung und ReceiveData
        $this->RegisterAttributeString('PlayersCache', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $root = $this->ReadPropertyInteger('PlayersRootID');
        if ($root <= 0) {
            $root = @IPS_CreateCategory();
            if ($root) {
                IPS_SetName($root, 'Music Assistant Players');
                IPS_SetParent($root, 0);
                IPS_SetProperty($this->InstanceID, 'PlayersRootID', $root);
                IPS_ApplyChanges($this->InstanceID);
            }
        }

        // Nur automatisch IO erzeugen, wenn explizit gewünscht
        if ($this->ReadPropertyBoolean('AutoCreateIO')) {
            $ioID = $this->EnsureWebSocketIO();
            if ($ioID > 0) {
                $this->EnsureIoConnection($ioID);
                if ($this->ReadPropertyInteger('WebSocketInstanceID') !== $ioID) {
                    IPS_SetProperty($this->InstanceID, 'WebSocketInstanceID', $ioID);
                    IPS_ApplyChanges($this->InstanceID);
                }
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'OnReceive') {
            $this->HandleReceive($Value);
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    public function GetConfigurationForm()
    {
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $path = $this->ReadPropertyString('Path');
        $ioID = $this->ReadAttributeInteger('WSInstanceID');

        $statusText = 'WebSocket: nicht erstellt';
        if ($ioID > 0) {
            $statusText = 'WebSocket ID ' . $ioID;
        }
        $url = '';
        if (!empty($host) && $port > 0) {
            $url = sprintf('ws://%s:%d%s', $host, $port, $path);
        }

        $form = [
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Host', 'value' => $host],
                ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535, 'value' => $port],
                ['type' => 'ValidationTextBox', 'name' => 'Path', 'caption' => 'Path', 'value' => $path],
                ['type' => 'CheckBox', 'name' => 'AutoCreateIO', 'caption' => 'WebSocket automatisch erstellen/aktualisieren', 'value' => $this->ReadPropertyBoolean('AutoCreateIO')],
                ['type' => 'Label', 'caption' => 'Geplante URL: ' . ($url ?: '(bitte Host/Port angeben)')],
                ['type' => 'Label', 'caption' => 'Status: ' . $statusText]
            ],
            'actions' => [
                // WICHTIG: Aufruf der öffentlichen Methode mit Modulpräfix und $id
                ['type' => 'Button', 'caption' => 'WebSocket jetzt erstellen/aktualisieren', 'onClick' => 'MABR_CreateOrUpdateIO($id);']
            ]
        ];
        return json_encode($form);
    }

    private function EnsureWebSocketIO(): int
    {
        $ioID = $this->ReadAttributeInteger('WSInstanceID');
        $auto = $this->ReadPropertyBoolean('AutoCreateIO');

        if ($ioID <= 0 && $auto) {
            $ioID = @IPS_CreateInstance(self::WS_CLIENT_GUID);
            if ($ioID) {
                IPS_SetName($ioID, 'MusicAssistant WS');
                IPS_SetParent($ioID, 0);

                $url = sprintf('ws://%s:%d%s',
                    $this->ReadPropertyString('Host'),
                    $this->ReadPropertyInteger('Port'),
                    $this->ReadPropertyString('Path')
                );
                @IPS_SetProperty($ioID, 'URL', $url);
                // WebSocket aktivieren (Eigenschaftsname beim WebSocket-Client: Active)
                @IPS_SetProperty($ioID, 'Active', true);
                @IPS_ApplyChanges($ioID);

                $this->WriteAttributeInteger('WSInstanceID', $ioID);
            }
        } elseif ($ioID > 0) {
            $url = sprintf('ws://%s:%d%s',
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyString('Path')
            );
            @IPS_SetProperty($ioID, 'URL', $url);
            @IPS_SetProperty($ioID, 'Active', true);
            @IPS_ApplyChanges($ioID);
        }

        return $ioID ?? 0;
    }

    private function EnsureIoConnection(int $ioID): void
    {
        // Bridge direkt mit IO verbinden, sodass ReceiveData aufgerufen werden kann
        if ((@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) !== $ioID) {
            @IPS_DisconnectInstance($this->InstanceID);
            @IPS_ConnectInstance($this->InstanceID, $ioID);
        }
    }

    public function ReceiveData($JSONString)
    {
        // Erwartetes Format: {"DataID":"...","Buffer":"<text>"}
        $data = @json_decode($JSONString, true);
        $buf = '';
        if (is_array($data)) {
            $buf = $data['Buffer'] ?? '';
        }
        if (!is_string($buf)) {
            $buf = '';
        }
        if ($buf === '') {
            return;
        }
        $this->HandleReceive($buf);
    }

    private function HandleReceive(string $raw): void
    {
        $msg = @json_decode($raw, true);
        if (!is_array($msg)) {
            return;
        }

        if (isset($msg['server_version'])) {
            $this->SendDebug('BANNER', $raw, 0);
            return;
        }

        if (($msg['event'] ?? '') === 'player_updated') {
            $pid = $msg['object_id'] ?? '';
            if ($pid === '') return;
            $data = $msg['data'] ?? [];
            $display = $data['display_name'] ?? ($data['name'] ?? $pid);
            $this->UpdatePlayersCache($pid, $display, $data);
            return;
        }

        $this->SendDebug('RECV', $raw, 0);
    }

    private function EnsureAndUpdatePlayerInstance(string $playerId, string $displayName, array $data): void
    {
        $root = $this->ReadPropertyInteger('PlayersRootID');
        if ($root <= 0) return;

        $iid = 0;
        foreach (IPS_GetChildrenIDs($root) as $child) {
            $inst = @IPS_GetInstance($child);
            if (!$inst) continue;
            if (($inst['ModuleInfo']['ModuleID'] ?? '') === self::PLAYER_MODULE_GUID) {
                if (IPS_GetProperty($child, 'PlayerID') === $playerId) {
                    $iid = $child; break;
                }
            }
        }

        if ($iid === 0) {
            $iid = @IPS_CreateInstance(self::PLAYER_MODULE_GUID);
            if ($iid) {
                IPS_SetParent($iid, $root);
                IPS_SetName($iid, 'MA - ' . $displayName);
                IPS_SetProperty($iid, 'PlayerID', $playerId);
                IPS_SetProperty($iid, 'DisplayName', $displayName);
                $io = $this->ReadAttributeInteger('WSInstanceID');
                IPS_SetProperty($iid, 'WebSocketInstanceID', $io);
                IPS_ApplyChanges($iid);
            }
        } else {
            $io = $this->ReadAttributeInteger('WSInstanceID');
            if (intval(IPS_GetProperty($iid, 'WebSocketInstanceID')) !== $io) {
                IPS_SetProperty($iid, 'WebSocketInstanceID', $io);
                IPS_ApplyChanges($iid);
            }
        }

        if ($iid > 0) {
            $idState = @IPS_GetObjectIDByIdent('State', $iid);
            $idVol   = @IPS_GetObjectIDByIdent('Volume', $iid);
            $idGVol  = @IPS_GetObjectIDByIdent('GroupVolume', $iid);
            if ($idState && array_key_exists('state', $data)) {
                @SetValueString($idState, strval($data['state']));
            }
            if ($idVol && array_key_exists('volume_level', $data)) {
                @SetValueFloat($idVol, floatval($data['volume_level']));
            }
            if ($idGVol && array_key_exists('group_volume', $data)) {
                @SetValueInteger($idGVol, intval($data['group_volume']));
            }
        }
    }

    // Manuell aus dem Konfig-Formular aufrufbar
    public function CreateOrUpdateIO(): void
    {
        // Plausibilitätsprüfung der Properties
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        if ($host === '' || $port <= 0) {
            $this->SendDebug('ERROR', 'Bitte Host und Port konfigurieren, dann erneut versuchen.', 0);
            return;
        }
        // IO sicherstellen/aktualisieren (ohne den AutoCreate-Property-Trick)
        // IO sicherstellen/aktualisieren
        $ioID = $this->EnsureWebSocketIO();
        if ($ioID > 0) {
            $this->EnsureIoConnection($ioID);
            if ($this->ReadPropertyInteger('WebSocketInstanceID') !== $ioID) {
                IPS_SetProperty($this->InstanceID, 'WebSocketInstanceID', $ioID);
                IPS_ApplyChanges($this->InstanceID);
            }
        }
    }
}
