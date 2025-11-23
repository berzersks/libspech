<?php

class DialogManager
{
    private \SQLite3 $db;

    public function __construct(string $dbPath = 'dialogs.db')
    {
        $this->db = new \SQLite3($dbPath);
        $this->initializeDatabase();
    }

    private function initializeDatabase(): void
    {
        // Criação da tabela dialogs
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS dialogs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                call_id TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Criação da tabela sessions com coluna user
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dialog_id INTEGER NOT NULL,
                original_ip TEXT NOT NULL,
                original_port INTEGER NOT NULL,
                proxy_ip TEXT NOT NULL,
                proxy_port INTEGER NOT NULL,
                user TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(dialog_id) REFERENCES dialogs(id) ON DELETE CASCADE
            );
        ");
    }

    public function createDialog(string $callId, string $state = 'IN_PROGRESS'): int
    {
        $query = "SELECT * FROM dialogs WHERE call_id = :call_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':call_id', $callId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $fetch = $result->fetchArray(SQLITE3_ASSOC);
        if ($fetch) {
            print \Plugin\Utils\cli::color('blue', 'Dialog already exists:' . $callId . ' ' . $fetch['id']) . PHP_EOL;
            return $fetch['id'];
        }


        $stmt = $this->db->prepare("INSERT INTO dialogs (call_id, state) VALUES (:call_id, :state)");
        $stmt->bindValue(':call_id', $callId, SQLITE3_TEXT);
        $stmt->bindValue(':state', $state, SQLITE3_TEXT);
        $stmt->execute();

        return $this->db->lastInsertRowID();
    }

    public function addSession(int $dialogId, string $originalIp, int $originalPort, string $proxyIp, int $proxyPort, string $user): int
    {
        // Verifica se já existe uma sessão com o mesmo IP e porta originais
        $stmt = $this->db->prepare("
        SELECT id 
        FROM sessions 
        WHERE dialog_id = :dialog_id AND original_ip = :original_ip AND original_port = :original_port
        and proxy_port = :proxy_port
    ");
        $stmt->bindValue(':dialog_id', $dialogId, SQLITE3_INTEGER);
        $stmt->bindValue(':original_ip', $originalIp, SQLITE3_TEXT);
        $stmt->bindValue(':original_port', $originalPort, SQLITE3_INTEGER);
        $stmt->bindValue(':proxy_port', $proxyPort, SQLITE3_INTEGER);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Sessão já existe, retorna o ID da sessão existente
            return $row['id'];
        } else {
            // Caso contrário, insere uma nova sessão
            $stmt = $this->db->prepare("
            INSERT INTO sessions (dialog_id, original_ip, original_port, proxy_ip, proxy_port, user)
            VALUES (:dialog_id, :original_ip, :original_port, :proxy_ip, :proxy_port, :user)
        ");
            $stmt->bindValue(':dialog_id', $dialogId, SQLITE3_INTEGER);
            $stmt->bindValue(':original_ip', $originalIp, SQLITE3_TEXT);
            $stmt->bindValue(':original_port', $originalPort, SQLITE3_INTEGER);
            $stmt->bindValue(':proxy_ip', $proxyIp, SQLITE3_TEXT);
            $stmt->bindValue(':proxy_port', $proxyPort, SQLITE3_INTEGER);
            $stmt->bindValue(':user', $user, SQLITE3_TEXT);
            $stmt->execute();

            // Retorna o ID da nova sessão criada
            return $this->db->lastInsertRowID();
        }
    }

    public function updateDialogState(string $callId, string $newState): void
    {
        $stmt = $this->db->prepare("UPDATE dialogs SET state = :state, timestamp = CURRENT_TIMESTAMP WHERE call_id = :call_id");
        $stmt->bindValue(':state', $newState, SQLITE3_TEXT);
        $stmt->bindValue(':call_id', $callId, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function getDialog(string $callId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM dialogs WHERE call_id = :call_id");
        $stmt->bindValue(':call_id', $callId, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC) ?: [];
    }

    public function getSessions(int $dialogId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM sessions WHERE dialog_id = :dialog_id");
        $stmt->bindValue(':dialog_id', $dialogId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $sessions = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $sessions[] = $row;
        }
        return $sessions;
    }

    public function deleteDialog(string $callId): void
    {
        $stmt = $this->db->prepare("DELETE FROM dialogs WHERE call_id = :call_id");
        $stmt->bindValue(':call_id', $callId, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function deleteSession(mixed $id): void
    {
        if (is_numeric($id)) {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
}
