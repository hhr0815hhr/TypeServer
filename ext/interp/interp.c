/*
 * 最小探针：AOT 产物里能不能执行外部 PHP 代码。
 *
 * 只做一件事：把一段 PHP 源码交给 zend_eval_string 执行，返回结果。
 * 如果这个能在 AOT 产物里跑通，说明：
 *   1. libphp.so 的编译/执行链路是活的（zend_compile_file 已初始化）
 *   2. PHP 扩展可以被 AOT 产物加载
 * 后面才有必要做「脚本文件热更 + 对象传递」。
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_interp.h"
#include "SAPI.h"
#include "ext/standard/info.h"

/* {{{ interp_eval_string(string $code): string|bool */
PHP_FUNCTION(interp_eval_string)
{
    char   *code;
    size_t  code_len;
    zval    retval;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(code, code_len)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&retval);

    /* 探针：确认实际收到的代码和长度（排查"expecting ;"那类解析错误） */
    if (getenv("INTERP_DEBUG") != NULL) {
        php_printf("DEBUG code_len=%zu code=[%s]\n", code_len, code);
    }

    if (zend_eval_stringl(code, code_len, &retval, "interp_eval_string") == FAILURE) {
        zend_error(E_WARNING, "interp_eval_string: eval failed");
        RETURN_FALSE;
    }

    if (Z_TYPE(retval) == IS_STRING) {
        RETVAL_STR(Z_STR(retval));
        return;
    }
    zval_ptr_dtor(&retval);
    RETURN_TRUE;
}
/* }}} */

/* {{{ interp_require_file(string $path): mixed
 *
 * 文件级编译 + 执行。比 zend_eval_stringl 更贴近真实需求：
 * 热更的是「脚本文件」，不是「代码字符串」。
 */
PHP_FUNCTION(interp_require_file)
{
    char   *path;
    size_t  path_len;
    zval    retval;
    zend_file_handle fh;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH(path, path_len)
    ZEND_PARSE_PARAMETERS_END();

    /* 8.5 里 zend_stream_init_filename 返回 void，打开失败由 zend_compile_file 报错 */
    zend_stream_init_filename(&fh, path);

    zend_op_array *op = zend_compile_file(&fh, ZEND_REQUIRE);
    zend_destroy_file_handle(&fh);

    if (op == NULL) {
        zend_error(E_WARNING, "interp_require_file: compile failed");
        RETURN_FALSE;
    }

    ZVAL_UNDEF(&retval);
    zend_execute(op, &retval);

    destroy_op_array(op);
    efree(op);

    if (Z_TYPE(retval) == IS_UNDEF) {
        RETURN_TRUE;
    }
    if (Z_TYPE(retval) == IS_STRING) {
        RETVAL_STR(Z_STR(retval));
        return;
    }
    zval_ptr_dtor(&retval);
    RETURN_TRUE;
}
/* }}} */

/* {{{ interp_probe(): array  —— 报告运行时状态，用来诊断为什么跑不起来 */
PHP_FUNCTION(interp_probe)
{
    array_init(return_value);
    add_assoc_bool(return_value, "compile_file_set",
        zend_compile_file != NULL);
    add_assoc_bool(return_value, "compile_string_set",
        zend_compile_string != NULL);
    add_assoc_string(return_value, "sapi", sapi_module.name ? sapi_module.name : "(none)");
    add_assoc_long(return_value, "executor_globals_init",
        (long) (EG(function_table) != NULL));
}
/* }}} */

ZEND_BEGIN_ARG_INFO_EX(arginfo_interp_eval_string, 0, 0, 1)
    ZEND_ARG_INFO(0, code)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_interp_probe, 0, 0, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry interp_functions[] = {
    PHP_FE(interp_eval_string,  arginfo_interp_eval_string)
    PHP_FE(interp_require_file, arginfo_interp_eval_string)
    PHP_FE(interp_probe,        arginfo_interp_probe)
    PHP_FE_END
};

PHP_MINFO_FUNCTION(interp)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "interp support", "enabled");
    php_info_print_table_row(2, "version", PHP_INTERP_VERSION);
    php_info_print_table_end();
}

zend_module_entry interp_module_entry = {
    STANDARD_MODULE_HEADER,
    "interp",
    interp_functions,
    NULL,   /* MINIT */
    NULL,   /* MSHUTDOWN */
    NULL,   /* RINIT */
    NULL,   /* RSHUTDOWN */
    PHP_MINFO(interp),
    PHP_INTERP_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_INTERP
ZEND_GET_MODULE(interp)
#endif
