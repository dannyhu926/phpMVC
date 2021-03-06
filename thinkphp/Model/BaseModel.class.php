<?php
namespace Common\Model;

use Think\Model;

/**
 * 基础model
 */
class BaseModel extends Model
{
    protected $updated_time_field = 'updated_time';
    protected $add_time_field = 'created_time';
    protected $soft_delete_field = 'deleted';
    protected $transactions = 0; //事物嵌套支持
    protected $excepts = []; //存储排除不查的字段
    protected $orginal = []; //存放模型原始数据

    public function startTrans() {
        ++$this->transactions;
        if ($this->transactions == 1) {
            $this->db->startTrans();
        }
    }

    public function rollback() {
        if ($this->transactions == 1) {
            $this->transactions = 0;
            $this->db->rollback();
        } else {
            --$this->transactions;
        }
    }

    public function commit() {
        if ($this->transactions == 1) $this->db->commit();
        --$this->transactions;
    }

    public function with() {
        $args = func_get_args();
        $name = array_shift($args);
        $method_name = 'with' . ucfirst($name);
        if (method_exists($this, $method_name)) {
            call_user_func_array([$this, $method_name], $args);
        }
        return $this;
    }

    /**
     * 更新数据前：
     * 在所有修改（包含逻辑删除）操作时，写数据中增加updated_time
     */
    protected function _before_update(&$data, $options) {
        if (!$data[$this->updated_time_field] && in_array($this->updated_time_field, $this->fields)) {
            $field_type = $this->fields['_type'][$this->updated_time_field];
            if (in_array($field_type, array('datetime', 'timestamp', 'date', 'time'))) { //日期类型
                $data[$this->updated_time_field] = date("Y-m-d H:i:s");
            } else { //时间戳类型
                $data[$this->updated_time_field] = time();
            }
        }
    }

    protected function _before_insert(&$data, $options) {
        if (!$data[$this->add_time_field] && in_array($this->add_time_field, $this->fields)) {
            $field_type = $this->fields['_type'][$this->add_time_field];
            if (in_array($field_type, array('datetime', 'timestamp', 'date', 'time'))) { //日期类型
                $data[$this->add_time_field] = date("Y-m-d H:i:s");
            } else { //时间戳类型
                $data[$this->add_time_field] = time();
            }
        }
    }

    /**
     * 添加数据
     * @param    array $data 数据
     * @return   integer           新增数据的id
     */
    public function addData($data) {
        $data = $this->filterDbFields($data);
        $id = $this->add($data);
        return $id;
    }

    /**
     * 修改数据
     * @param    array $map where语句数组形式
     * @param    array $data 修改的数据
     * @return    boolean         操作是否成功
     */
    public function editData($map, $data) {
        if (!is_array($map)) {
            $condition[$this->pk] = $map;
        } else {
            $condition = $map;
        }
        $data = $this->filterDbFields($data);
        $this->where($condition)->save($data);
        return empty($this->getError());
    }

    /**
     * 删除数据
     * @param    array $map where语句数组形式
     * @return   boolean          操作是否成功
     */
    public function deleteData($map) {
        if (!is_array($map)) {
            $condition[$this->pk] = $map;
        } else {
            $condition = $map;
        }
        $result = $this->where($condition)->delete();
        return $result;
    }

    /**
     *  分页取出所有记录
     * @param    mixed $condtion where语句数组形式
     * @return   boolean          操作是否成功
     */
    public function getPagerList($page, $condtion = [], $pageSize, $orderBy = '') {
        $offset = ($page - 1) * $pageSize;
        if (empty($orderBy)) {
            $orderBy = "{$this->pk} DESC";
        }
        $count = $this->where($condtion)->count();
        $result['list'] = $this->where($condtion)->order($orderBy)->limit("$offset, $pageSize")->select();
        $result['pager'] = ['total' => $count, 'page' => $page, "page_size" => $pageSize];
        return $result;
    }

    public function filterDbFields($data) {
        $fields = array_keys($data);

        $list = $this->getDbFields();
        foreach ($fields as $key) {
            if (!in_array($key, $list)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    //查询一个字段
    public function fetchOne($id, $field) {
        return $this->where([$this->pk => $id])->getField($field);
    }

    public function fetchRow($id, $field = "*") {
        return $this->field($field)->where([$this->pk => $id])->find();
    }

    public function fetchAll($condtion = [], $orderBy = '') {
        if (empty($orderBy)) {
            $orderBy = "{$this->pk} DESC";
        }
        return $this->where($condtion)->order($orderBy)->select();
    }

    public function softDelete($id) {
        return $this->editData([$this->pk => $id], [$this->soft_delete_field => 1]);
    }

    public function deleteByPk($id) {
        $result = $this->where([$this->pk => $id])->delete();
        return $result;
    }

    /**
     * 取得数据表的字段信息
     * @access public
     * @return array
     */
    public function getFields() {
        $result = $this->query('SHOW FULL COLUMNS FROM ' . $this->getTableName());
        $columns = array();
        foreach ($result as $val) {
            $columns[$val['field']] = array(
                'name' => $val['field'],
                'type' => $val['type'],
                'notnull' => (bool)($val['null'] === ''), // not null is empty, null is yes
                'default' => $val['default'],
                'comment' => $val['comment'],
                'primary' => (strtolower($val['key']) == 'pri'),
                'autoinc' => (strtolower($val['extra']) == 'auto_increment'),
            );
        }
        return $columns;
    }

    /**
     * 在查询的时候排除某个字段不查
     * @return $this
     */
    public function except() {
        foreach (func_get_args() as $field) {
            if (is_string($field) && !in_array($field, $this->excepts)) {
                if (strpos($field, ',') !== false) {
                    $field = explode(',', $field);
                } else {
                    $field = array($field);
                }
            }
            if (is_array($field)) {
                $this->excepts = array_merge($field, $this->excepts);
            }
        }
        return $this;
    }

    /**
     * 添加过滤规则，让其支持except
     * @param $options
     */
    protected function _options_filter(&$options) {
        if (count($this->excepts) > 0) {
            if (!isset($options['field'])) {
                $options['field'] = $this->getDbFields();
            }
            foreach ($options['field'] as $i => $field) {
                if (in_array($field, $this->excepts)) {
                    unset($options['field'][$i]);
                }
            }
        }
    }

    /**
     * 获取修改的字段
     * @return array
     */
    public function getDirty() {
        $result = [];
        foreach ($this->getDbFields() as $field) {
            if ($this->isDirty($field)) {
                $result[$field] = $this->data[$field];
            }
        }
        return $result;
    }

    public function save($data = '', $options = array()) {
        if (empty($data)) {
            $data = $this->getDirty();
        }
        return parent::save($data, $options);
    }

    /**
     * 检测字段是否修改
     * @param $attr
     * @return bool
     */
    public function isDirty($attr) {
        if (!isset($this->data[$attr])) {
            return false;
        }
        return $this->data[$attr] !== $this->orginal[$attr];
    }
}
