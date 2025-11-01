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
        // Empfangskette per RegisterVariable unter der Bridge
        $this->RegisterAttributeInteger('RegVarID', 0);
        $this->RegisterAttributeInteger('RecvScriptID', 0);
        $this->RegisterAttributeInteger('LinkRegVarID', 0);
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
                ['type' => 'Button', 'caption' => 'WebSocket jetzt erstellen/aktualisieren', 'onClick' => 'MABR_CreateOrUpdateIO($id);'],
                ['type' => 'Button', 'caption' => 'MA Daten schnüffeln (5s)', 'onClick' => 'MABR_Probe($id, 5);'],
                ['type' => 'Button', 'caption' => 'MA Daten schnüffeln (10s)', 'onClick' => 'MABR_Probe($id, 10);']
            ]
        ];
        return json_encode($form);
    }

    private function EnsureWebSocketIO(): int
    {
        $ioID = $this->ReadAttributeInteger('WSInstanceID');
        $auto = $this->ReadPropertyBoolean('AutoCreateIO');
        $desiredName = 'MusicAssistant WS';

        if ($ioID <= 0 && $auto) {
            // Zuerst nach vorhandener WS-Instanz mit dem gewünschten Namen suchen
            foreach (IPS_GetInstanceListByModuleID(self::WS_CLIENT_GUID) as $candidate) {
                if (strcasecmp(IPS_GetName($candidate), $desiredName) === 0) {
                    $ioID = $candidate;
                    break;
                }
            }
            if ($ioID <= 0) {
                $ioID = @IPS_CreateInstance(self::WS_CLIENT_GUID);
                if ($ioID) {
                    IPS_SetName($ioID, $desiredName);
                    IPS_SetParent($ioID, 0);
                }
            }
            if ($ioID) {

                $url = sprintf('ws://%s:%d%s',
                    $this->ReadPropertyString('Host'),
                    $this->ReadPropertyInteger('Port'),
                    $this->ReadPropertyString('Path')
                );
                @IPS_SetProperty($ioID, 'URL', $url);
                // WebSocket aktivieren (verschiedene IPS-Versionen nutzen 'Active' oder 'Open')
                @IPS_SetProperty($ioID, 'Active', true);
                @IPS_SetProperty($ioID, 'Open', true);
                @IPS_ApplyChanges($ioID);
                $this->SendDebug('IO', 'Configured URL='.$url.' and activated IO ID='.$ioID, 0);

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
            @IPS_SetProperty($ioID, 'Open', true);
            @IPS_ApplyChanges($ioID);
            $this->SendDebug('IO', 'Updated URL='.$url.' and ensured activation for IO ID='.$ioID, 0);
        }

        return $ioID ?? 0;
    }

    private function EnsureReceiveChain(int $ioID): void
    {
        // RegisterVariable erstellen/verbinden (IP-Symcon hängt sie i. d. R. unter das IO)
        // Richtige GUID der RegisterVariable
        $rvGUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
        $regVarID = $this->ReadAttributeInteger('RegVarID');
        if ($regVarID <= 0 || !@IPS_InstanceExists($regVarID)) {
            $regVarID = @IPS_CreateInstance($rvGUID);
            IPS_SetName($regVarID, 'MA WS RegisterVariable');
            $this->WriteAttributeInteger('RegVarID', $regVarID);
            $this->SendDebug('CHAIN', 'Created RegisterVariable ID='.$regVarID, 0);
        }
        // Verbindung zum IO herstellen
        if ((@IPS_GetInstance($regVarID)['ConnectionID'] ?? 0) !== $ioID) {
            @IPS_DisconnectInstance($regVarID);
            @IPS_ConnectInstance($regVarID, $ioID);
            $this->SendDebug('CHAIN', 'Connected RegVar '.$regVarID.' to IO '.$ioID, 0);
        }
        // Zielskript anlegen/setzen
        $scriptID = $this->ReadAttributeInteger('RecvScriptID');
        $want = '<?php IPS_RequestAction(' . $this->InstanceID . ', "OnReceive", $_IPS["VALUE"]);';
        if ($scriptID <= 0 || !@IPS_ScriptExists($scriptID)) {
            $scriptID = IPS_CreateScript(0);
            IPS_SetName($scriptID, 'MA Bridge Receive');
            IPS_SetParent($scriptID, $this->InstanceID);
            IPS_SetScriptContent($scriptID, $want);
            $this->WriteAttributeInteger('RecvScriptID', $scriptID);
            $this->SendDebug('CHAIN', 'Created Receive script ID='.$scriptID, 0);
        } else {
            IPS_SetScriptContent($scriptID, $want);
        }
        @IPS_SetProperty($regVarID, 'VariableType', 3); // String
        @IPS_SetProperty($regVarID, 'TargetID', $scriptID);
        @IPS_ApplyChanges($regVarID);

        // Sichtbarkeit unter der Bridge: Link auf die RegVar anlegen/aktualisieren
        $linkID = $this->ReadAttributeInteger('LinkRegVarID');
        if ($linkID <= 0 || !@IPS_LinkExists($linkID)) {
            $linkID = IPS_CreateLink();
            IPS_SetName($linkID, 'MA WS RegisterVariable');
            IPS_SetParent($linkID, $this->InstanceID);
            $this->WriteAttributeInteger('LinkRegVarID', $linkID);
        } else {
            if (@IPS_GetParent($linkID) !== $this->InstanceID) {
                IPS_SetParent($linkID, $this->InstanceID);
            }
        }
        @IPS_SetLinkTargetID($linkID, $regVarID);
    }

    public function ReceiveData($JSONString)
    {
        $data = @json_decode($JSONString, true);
        if (!is_array($data)) {
            return;
        }
        $buf = $data['Buffer'] ?? '';
        if ($buf === '') {
            return;
        }
        $this->HandleReceive($buf);
    }

    private function EnsureIoConnection(int $ioID): void
    {
        $current = @IPS_GetInstance($this->InstanceID);
        $connected = intval($current['ConnectionID'] ?? 0);
        if ($connected !== $ioID) {
            @IPS_DisconnectInstance($this->InstanceID);
            @IPS_ConnectInstance($this->InstanceID, $ioID);
            $this->SendDebug('CHAIN', 'Connected Bridge '.$this->InstanceID.' to IO '.$ioID, 0);
        }
    }

    // ReceiveData wird hier nicht genutzt; Empfang erfolgt über RegisterVariable Target-Script

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

    // Interner, einfacher WebSocket-Client zum kurzfristigen Mitschneiden von Textframes (JSON)
    public function Probe(int $seconds = 5): void
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = intval($this->ReadPropertyInteger('Port'));
        $path = $this->ReadPropertyString('Path') ?: '/ws';
        if ($host === '' || $port <= 0) {
            $this->SendDebug('PROBE', 'Host/Port nicht gesetzt', 0);
            return;
        }
        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if (!$fp) {
            $this->SendDebug('PROBE', 'fsockopen failed: '.$errstr, 0);
            return;
        }
        stream_set_timeout($fp, 1);
        $key = base64_encode(random_bytes(16));
        $req = "GET ".$path." HTTP/1.1\r\n".
               "Host: ".$host.":".$port."\r\n".
               "Upgrade: websocket\r\n".
               "Connection: Upgrade\r\n".
               "Sec-WebSocket-Key: ".$key."\r\n".
               "Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($fp, $req);
        // Read HTTP response headers
        $hdr = '';
        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) { break; }
            $hdr .= $line;
            if (rtrim($line) === '') { break; }
        }
        if (stripos($hdr, '101') === false) {
            $this->SendDebug('PROBE', 'Handshake failed: '.$hdr, 0);
            fclose($fp);
            return;
        }
        $end = time() + max(1, $seconds);
        while (time() < $end) {
            $frame = $this->wsRecvFrame($fp);
            if ($frame === null) { continue; }
            if ($frame['op'] === 0x1 && $frame['payload'] !== '') { // text frame
                $this->HandleReceive($frame['payload']);
            }
        }
        fclose($fp);
        $this->SendDebug('PROBE', 'done', 0);
    }

    private function wsReadN($fp, int $n)
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === false || $chunk === '') { return null; }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function wsRecvFrame($fp): ?array
    {
        $h = $this->wsReadN($fp, 2);
        if ($h === null) { return null; }
        $b1 = ord($h[0]);
        $b2 = ord($h[1]);
        $fin = ($b1 & 0x80) !== 0;
        $op  = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $len = $b2 & 0x7F;
        if ($len === 126) {
            $ext = $this->wsReadN($fp, 2); if ($ext === null) return null;
            $len = (ord($ext[0]) << 8) | ord($ext[1]);
        } elseif ($len === 127) {
            $ext = $this->wsReadN($fp, 8); if ($ext === null) return null;
            // 64-bit len; we cap to PHP int
            $len = 0; for ($i=0; $i<8; $i++) { $len = ($len << 8) | ord($ext[$i]); }
        }
        $maskKey = '';
        if ($masked) {
            $maskKey = $this->wsReadN($fp, 4); if ($maskKey === null) return null;
        }
        $payload = $len > 0 ? $this->wsReadN($fp, $len) : '';
        if ($payload === null) return null;
        if ($masked && $payload !== '') {
            $unmasked = '';
            for ($i=0; $i<strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }
        return ['fin' => $fin, 'op' => $op, 'payload' => $payload];
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
