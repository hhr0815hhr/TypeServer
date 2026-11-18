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

    /* 8.5 里 zend_stream_init_filename 返回 void，打开失败由编译阶段报错 */
    zend_stream_init_filename(&fh, path);

    /* 用 zend_execute_scripts 而不是自己 compile+execute+destroy：
     * 前者走完整的 require 流程，脚本里定义的函数/类会被正确注册到符号表；
     * 后者 destroy_op_array 会把函数表一起销毁（实测 function_exists 返回 false）。
     */
    ZVAL_UNDEF(&retval);
    int rc = zend_execute_scripts(ZEND_REQUIRE, &retval, 1, &fh);
    zend_destroy_file_handle(&fh);

    if (rc == FAILURE) {
        zend_error(E_WARNING, "interp_require_file: execute failed");
        RETURN_FALSE;
    }

    if (Z_TYPE(retval) == IS_UNDEF) {
        RETURN_TRUE;
    }
    /* 任意类型都原样返回 —— 让脚本能 return 一个闭包或数组作为"导出表"。
     * 这一点很重要：PHP 不允许重复定义同名函数，所以热更的脚本不能靠
     * function xxx() 导出，只能 return 闭包（每次 require 得到新闭包）。 */
    RETURN_COPY_VALUE(&retval);
}
/* }}} */

/* {{{ interp_call_obj(string $fn, object $obj): mixed
 *
 * 调用脚本里定义的函数，把宿主侧的对象作为参数传进去。
 *
 * 这是「脚本操作宿主数据」的核心动作：
 *   脚本里 $obj->gold += 100，宿主侧的同一个对象应该跟着变。
 *
 * 前提：脚本已经 interp_require_file 过（函数已注册到符号表）。
 */
PHP_FUNCTION(interp_call_obj)
{
    zend_string *fn;
    zval        *obj;
    zval         retval;
    zval         args[1];

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(fn)
        Z_PARAM_ZVAL(obj)
    ZEND_PARSE_PARAMETERS_END();

    if (Z_TYPE_P(obj) != IS_OBJECT) {
        zend_error(E_WARNING, "interp_call_obj: argument 2 must be object, got type %d", Z_TYPE_P(obj));
        RETURN_FALSE;
    }

    ZVAL_COPY(&args[0], obj);   /* 引用传递：脚本里改的就是同一个对象 */

    /* call_user_function 的第三个参数是 zval*（函数名），不是 zend_string*。
     * 直接传 zend_string* 会造成栈破坏（实测 stack smashing detected）。
     */
    zval fname_zv;
    ZVAL_STR_COPY(&fname_zv, fn);

    if (call_user_function(EG(function_table), NULL, &fname_zv, &retval, 1, args) != SUCCESS) {
        zval_ptr_dtor(&fname_zv);
        zval_ptr_dtor(&args[0]);
        zend_error(E_WARNING, "interp_call_obj: function %s not found or failed", ZSTR_VAL(fn));
        RETURN_FALSE;
    }
    zval_ptr_dtor(&fname_zv);
    zval_ptr_dtor(&args[0]);

    if (Z_TYPE(retval) == IS_LONG) {
        RETVAL_LONG(Z_LVAL(retval));
        return;
    }
    if (Z_TYPE(retval) == IS_STRING) {
        RETVAL_STR(Z_STR(retval));
        return;
    }
    if (Z_TYPE(retval) == IS_DOUBLE) {
        RETVAL_DOUBLE(Z_DVAL(retval));
        return;
    }
    zval_ptr_dtor(&retval);
    RETURN_TRUE;
}
/* }}} */

/* {{{ interp_invoke(callable $fn, object $obj): mixed
 *
 * 调用一个「运行期拿到的」闭包。
 *
 * 为什么不直接在 PHP 侧写 $fn($obj)：
 * AOT 编译期必须确定调用目标的签名，$fn 是运行期才知道的闭包，
 * tpc 会拒绝（见 docs/guide/08 的"闭包/动态调用"那条）。
 * 所以动态调用统一走这个 C 层入口。
 */
PHP_FUNCTION(interp_invoke)
{
    zval *fn;
    zval *obj;
    zval  retval;
    zval  args[1];

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(fn)
        Z_PARAM_ZVAL(obj)
    ZEND_PARSE_PARAMETERS_END();

    if (!zend_is_callable(fn, 0, NULL)) {
        zend_error(E_WARNING, "interp_invoke: argument 1 is not callable");
        RETURN_FALSE;
    }

    ZVAL_COPY(&args[0], obj);

    /* 第一个参数传 NULL：从 $fn 本身取调用目标（闭包 / 类方法 / 函数名都行） */
    if (call_user_function(NULL, NULL, fn, &retval, 1, args) != SUCCESS) {
        zval_ptr_dtor(&args[0]);
        zend_error(E_WARNING, "interp_invoke: call failed");
        RETURN_FALSE;
    }
    zval_ptr_dtor(&args[0]);

    if (Z_TYPE(retval) == IS_UNDEF) {
        RETURN_TRUE;
    }
    RETURN_COPY_VALUE(&retval);
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
    PHP_FE(interp_call_obj,     arginfo_interp_eval_string)
    PHP_FE(interp_invoke,       arginfo_interp_eval_string)
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
