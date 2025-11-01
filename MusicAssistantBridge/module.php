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
        $this->RegisterAttributeInteger('RegVarID', 0);
        $this->RegisterAttributeInteger('RecvScriptID', 0);
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
                $this->EnsureReceiveChain($ioID);
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

    private function EnsureReceiveChain(int $ioID): void
    {
        $regVarID = $this->ReadAttributeInteger('RegVarID');
        $rvGUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}'; // Register Variable

        // Falls keine bekannte RegVar gespeichert ist, versuche vorhandene unterhalb des IO zu finden
        if ($regVarID <= 0) {
            foreach (IPS_GetChildrenIDs($ioID) as $child) {
                $inst = @IPS_GetInstance($child);
                if ($inst && ($inst['ModuleInfo']['ModuleID'] ?? '') === $rvGUID) {
                    $regVarID = $child;
                    break;
                }
            }
        }

        if ($regVarID <= 0) {
            $regVarID = @IPS_CreateInstance($rvGUID);
            IPS_SetName($regVarID, 'MA WS RegisterVariable');
        }

        // Sicherstellen, dass die RegVar unter dem IO hängt und korrekt konfiguriert ist
        if (@IPS_GetParent($regVarID) !== $ioID) {
            IPS_SetParent($regVarID, $ioID);
        }
        @IPS_SetProperty($regVarID, 'VariableType', 3); // String
        @IPS_ApplyChanges($regVarID);
        $this->WriteAttributeInteger('RegVarID', $regVarID);

        $scriptID = $this->ReadAttributeInteger('RecvScriptID');
        $want = '<?php IPS_RequestAction(' . $this->InstanceID . ', "OnReceive", $_IPS["VALUE"]);';
        if ($scriptID <= 0 || !@IPS_ScriptExists($scriptID)) {
            $scriptID = IPS_CreateScript(0);
            IPS_SetName($scriptID, 'MA Bridge Receive');
            IPS_SetParent($scriptID, $this->InstanceID);
            IPS_SetScriptContent($scriptID, $want);
            $this->WriteAttributeInteger('RecvScriptID', $scriptID);
        } else {
            IPS_SetScriptContent($scriptID, $want);
        }
        @IPS_SetProperty($regVarID, 'TargetID', $scriptID);
        @IPS_ApplyChanges($regVarID);
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
            $this->EnsureAndUpdatePlayerInstance($pid, $display, $data);
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
        IPS_SetProperty($this->InstanceID, 'AutoCreateIO', true);
        IPS_ApplyChanges($this->InstanceID);

        $ioID = $this->EnsureWebSocketIO();
        if ($ioID > 0) {
            $this->EnsureReceiveChain($ioID);
            if ($this->ReadPropertyInteger('WebSocketInstanceID') !== $ioID) {
                IPS_SetProperty($this->InstanceID, 'WebSocketInstanceID', $ioID);
                IPS_ApplyChanges($this->InstanceID);
            }
        }
    }
}
