<?php

namespace Edgvi10;

class DBWalker
{
    protected $host;
    protected $user;
    protected $pass;
    protected $base;
    public $link;
    public $params;

    function __construct(array $mysql_access, $debug = false)
    {
        $this->debug = $debug;

        list($this->host, $this->user, $this->pass, $this->base) = $mysql_access;

        $this->link = new \MySQLi($this->host, $this->user, $this->pass, $this->base);
        if (mysqli_connect_errno()) {
            exit(["success" => false, "message" => "Can't connect DB" . mysqli_connect_error()]);
        }
        $this->link->set_charset('utf8');
        $this->link->query("SET time_zone = '-3:00'");
    }

    function __destruct()
    {
        if (isset($this->link)) :
            mysqli_close($this->link);
            $this->link = false;
            return true;
        endif;
    }

    private function isAssoc(array $arr)
    {
        if (array() === $arr) return false;

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function query($query)
    {
        $result = $this->link->query(trim($query));
        return $result;
    }

    public function escapestring($string)
    {
        $string = $this->link->escape_string(trim($string));

        return $string;
    }

    public function UUID()
    {
        $select = $this->query("SELECT UUID() AS `uuid` ");
        $uuid = $select->fetch_object()->uuid;
        return $uuid;
    }

    private function tableName($query_table)
    {
        $table_name = explode(" AS ", $query_table);
        $query_table = $table_name[0];
        if (isset($table_name[1])) $as = $table_name[1];

        if (strpos(".", $query_table) !== FALSE) :
            $query_table = explode(".", $query_table);
            $query_table[0] = trim($query_table[0], "`");
            if (isset($query_table[1])) $query_table[0] = trim($query_table[1], "`");
            $query_table = "`" . implode("`.`", $query_table) . "`";
        else :
            $query_table = "`" . trim($query_table, "`") . "`";
        endif;

        if (isset($as)) $query_table .= " AS {$as}";

        return $query_table;
    }

    private function value($value)
    {
        if ((!is_int($value) || !is_float($value)) && $value !== "NOW()" && $value !== "UUID()" && $value !== "NULL")
            $value = "'" . $this->link->escape_string(trim($value)) . "'";

        return $value;
    }

    private function where($where)
    {
        if (is_null($where)) :
            $query_where = NULL;
        elseif (is_string($where)) :
            $query_where = " WHERE " . $where;
        else :
            if ($this->isAssoc($where)) :
                $params = array();
                foreach ($where as $param => $value) :
                    if (!is_array($value))
                        $value = $this->value($value);

                    if (stripos($param, "param_") === 0) $params[] = sprintf("`%s` = {$value}", str_replace("param_", "", $param));
                    elseif (stripos($param, "like_") === 0) $params[] = sprintf("`%s` LIKE '%%{$value}%%'", str_replace("like_", "", $param));
                    elseif (stripos($param, "null_") === 0) $params[] = sprintf("`%s` IS NULL", str_replace("null_", "", $param));
                    elseif (stripos($param, "notnull_") === 0) $params[] = sprintf("`%s` IS NOT NULL", str_replace("notnull_", "", $param));
                    elseif (stripos($param, "contain_") === 0) $params[] = sprintf("FIND_IN_SET({$value}, `%s`)", str_replace("contain_", "", $param));
                    elseif (stripos($param, "between_") === 0) $params[] = sprintf("`%s` BETWEEN '{$value[0]}' AND '{$value[1]}'", str_replace("between_", "", $param));

                    elseif (stripos($param, "upperequal_") === 0) $params[] = sprintf("`%s` >= {$value}", str_replace("upperequal_", "", $param));
                    elseif (stripos($param, "underequal_") === 0) $params[] = sprintf("`%s` <= {$value}", str_replace("underequal_", "", $param));

                    elseif (stripos($param, "upper_") === 0) $params[] = sprintf("`%s` > {$value}", str_replace("upper_", "", $param));
                    elseif (stripos($param, "under_") === 0) $params[] = sprintf("`%s` < {$value}", str_replace("under_", "", $param));

                    elseif ($param === "raw") foreach ($value as $val) $params[] = $val;
                    else $params[] = sprintf("`%s` = {$value}", $param);
                endforeach;

                $where = $params;
            endif;

            $query_where = " WHERE " . implode(" AND ", $where);
        endif;

        return $query_where;
    }

    private function joins($joins)
    {
        if (is_null($joins)) :
            $query_joins = NULL;
        elseif (is_string($joins)) :
            $query_joins = " INNER JOIN " . $joins;
        else :
            $joins_arr = [];
            foreach ($joins as $join) :
                $join_direction = (isset($join["direction"])) ? strtoupper($join["direction"]) : $join[0];
                $join_table = (isset($join["table"])) ? $join["table"] : $join[1];
                $join_on = (isset($join["on"])) ? ((is_string($join["on"])) ? $join["on"] : implode(" AND ", $join["on"])) : $join[2];

                $joins_arr[] = " {$join_direction} JOIN {$join_table} ON ({$join_on})";
            endforeach;

            $query_joins = implode(" ", $joins_arr);
        endif;

        return $query_joins;
    }

    ///////////////////////////////////////////////////////////////////////////////////
    ///  SELECT ///////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////
    public function select($options, $debug = false)
    {
        // GET options
        $query_table = $options["table"];
        $columns = (isset($options["columns"])) ? $options["columns"] : null;
        $columns_hide = (isset($options["columns_hide"])) ? $options["columns_hide"] : null;
        $joins = (isset($options["joins"])) ? $options["joins"] : null;
        $where = (isset($options["where"])) ? $options["where"] : null;
        $order = (isset($options["order_by"])) ? $options["order_by"] : null;
        $group = (isset($options["group_by"])) ? $options["group_by"] : null;
        $limit = (isset($options["limit"])) ? $options["limit"] : null;
        $offset = (isset($options["offset"])) ? $options["offset"] : ((isset($options["limit"])) ? 0 : NULL);

        if (is_array($columns)) $columns = implode(", ", $columns);

        $query_table = $this->tableName($options["table"]);
        $query_where = $this->where($where);
        $query_joins = $this->joins($joins);

        if (is_array($order)) :
            foreach ($order as $key) :
                if (stripos($key, "orderasc_") === 0) $orderby[] = sprintf("`%s` ASC", str_replace("orderasc_", "", $key));
                if (stripos($key, "orderdesc_") === 0) $orderby[] = sprintf("`%s` DESC", str_replace("orderdesc_", "", $key));
                if (stripos($key, "sort") === 0) $orderby[] = ("`order` > 0 DESC, CAST(`order` AS unsigned) ASC");
            endforeach;

            $order = implode(", ", $orderby);
        endif;

        $query_columns = (!is_null($columns)) ? $columns : $query_columns = "*";
        $query_order = (!is_null($order)) ? " ORDER BY " . $order : NULL;
        $query_group = (!is_null($group)) ? " GROUP BY " . $group : NULL;
        $query_limit = (!is_null($limit) && 0 < $limit) ? " LIMIT " . $limit : NULL;
        $query_offset = (!is_null($limit) && !is_null($offset) && 0 <= $offset) ? " OFFSET " . $offset : NULL;

        $query = "SELECT {$query_columns} FROM {$query_table}{$query_joins}{$query_where}{$query_group}{$query_order}{$query_limit}{$query_offset}";

        $response = [];
        $response["success"] = false;
        if ($this->debug || $debug) $response["options"] = $options;
        if ($this->debug || $debug) $response["query"] = $query;

        if (!$result = $this->link->query($query)) :
            $response["message"] = "Erro MySQL: " . $this->link->error;
        else :
            $response["success"] = true;
            $response["results"] = $result->num_rows;
            $response["found"] = $result->num_rows;

            if (!is_null($limit) && 0 < $result->num_rows) :
                $table_name = array_reverse(explode(" ", $query_table))[0];

                $query_total = "SELECT COUNT(*) AS total FROM {$query_table}{$query_joins}{$query_where}";
                if (!$result_total = $this->link->query($query_total)) :
                    $response["query_total"] = $query_total;
                    $response["message_total"] = $this->link->error;
                else :
                    $result_total = $result_total->fetch_object();
                    $response["total"] = (int) $result_total->total;
                    $response["limit"] = (int) $limit;
                endif;
            endif;

            $response["data"] = [];
            while ($row = $result->fetch_object()) :
                if (isset($row->id)) $row->id = (int) $row->id;
                if (isset($row->active)) $row->active = (bool) $row->active;

                if (!empty($columns_hide))
                    foreach ($columns_hide as $column)
                        unset($row->$column);

                $response["data"][] = $row;
            endwhile;
        endif;

        return $response;
    }

    ///////////////////////////////////////////////////////////////////////////////////
    ///  INSERT  //////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////
    public function insert($options, $debug = false)
    {
        $query_table = $options["table"];
        $data = $options["data"];

        $multiple = false;
        if ($this->isAssoc($data)) :
            foreach ($data as $key => $value) :
                $value = $this->value($value);

                $cols[] = $key;
                $values[] = $value;
            endforeach;

            $query_cols = implode(", ", $cols);
            $query_values = implode(", ", $values);
        else :
            foreach ($data as $row) :
                $cols = [];
                $values = [];
                foreach ($row as $key => $value) :
                    $value = $this->value($value);

                    $cols[] = $key;
                    $values[] = $value;
                endforeach;

                $query_cols = implode(", ", $cols);
                $query_values[] = implode(", ", $values);
            endforeach;

            $query_values = implode("),\n(", $query_values);
            $multiple = true;
        endif;

        $query_table = $this->tableName($options["table"]);

        $query = "INSERT INTO $query_table ($query_cols) VALUES ($query_values)";

        $response = [];
        $response["success"] = false;
        if ($this->debug || $debug) $response["options"] = $options;
        if ($this->debug || $debug) $response["query"] = $query;

        if (!$insert = $this->link->query($query)) :
            $response["message"] = $this->link->error;
        else :
            $id = $this->link->insert_id;
            $affected_rows = $this->link->affected_rows;

            $response["success"] = true;
            $response["affected_rows"] = $affected_rows;

            if (!$multiple) :
                if (!empty($id)) :
                    $response["insert_id"] = $id;
                    $result = $this->select(["table" => $query_table, "where" => ["{$query_table}.id" => $id]]);
                    if ($result["success"]) :
                        $response["data"] = $result["data"][0];
                    endif;
                endif;
            endif;
        endif;

        return $response;
    }

    ///////////////////////////////////////////////////////////////////////////////////
    ///  UPDATE ///////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////
    public function update($options, $debug = false)
    {
        // GET options
        $query_table = $options["table"];
        $where = (isset($options["where"])) ? $options["where"] : null;
        $data = (isset($options["data"])) ? $options["data"] : null;


        foreach ($data as $key => $value) :
            $value = $this->value($value);

            $query_fields[] = "`{$key}` = {$value}";
        endforeach;

        $query_fields = " SET " . implode(", ", $query_fields);

        $query_table = $this->tableName($options["table"]);
        $query_where = $this->where($where);

        $query = "UPDATE {$query_table}{$query_fields}{$query_where}";

        $response = [];
        $response["success"] = false;
        if ($this->debug || $debug) $response["options"] = $options;
        if ($this->debug || $debug) $response["query"] = $query;

        if (!$result = $this->link->query($query)) :
            $response["message"] = $this->link->error;
        else :
            $response["success"] = true;
            $affected_rows = $this->link->affected_rows;
            $response["affected_rows"] = $affected_rows;
        endif;

        return $response;
    }

    //////////////////////////////////////////////////////////////////////////////////
    ///  DELETE //////////////////////////////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////////////
    public function delete($options, $debug = false)
    {
        $query_table = $options["table"];
        $where = (isset($options["where"])) ? $options["where"] : null;

        $query_table = $this->tableName($options["table"]);
        $query_where = $this->where($where);

        $query = "DELETE FROM {$query_table}{$query_where}";

        $response = [];
        $response["success"] = false;
        if ($this->debug || $debug) $response["options"] = $options;
        if ($this->debug || $debug) $response["query"] = $query;

        if (!$result = $this->link->query($query)) :
            $response["message"] = $this->link->error;
        else :
            $response["success"] = true;
            $affected_rows = $this->link->affected_rows;
            $response["affected_rows"] = $affected_rows;
        endif;

        return $response;
    }
}
