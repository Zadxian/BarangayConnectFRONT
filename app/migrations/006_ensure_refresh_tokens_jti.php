<?php

class Ensure_refresh_tokens_jti
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $column = $this->_lava->db->raw(
            "SHOW COLUMNS FROM refresh_tokens LIKE 'jti'"
        )->fetch(PDO::FETCH_ASSOC);

        if (!$column) {
            $this->_lava->dbforge->add_column('refresh_tokens', [
                'jti' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'null'       => FALSE,
                    'default'    => '',
                ],
            ]);
        }
    }

    public function down()
    {
    }
}