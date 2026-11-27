"""Export through LibreOfficeKit without the desktop CLI's Unix socket listener."""
import ctypes
import os
import sys
from pathlib import Path


Pointer = ctypes.c_void_p
CString = ctypes.c_char_p
Destroy = ctypes.CFUNCTYPE(None, Pointer)


class OfficeApi(ctypes.Structure):
    # Initial entries of LibreOfficeKitClass, in public C ABI order.
    _fields_ = [
        ('size', ctypes.c_size_t),
        ('destroy', Destroy),
        ('load', ctypes.CFUNCTYPE(Pointer, Pointer, CString)),
        ('get_error', ctypes.CFUNCTYPE(CString, Pointer)),
    ]


class DocumentApi(ctypes.Structure):
    # Initial entries of LibreOfficeKitDocumentClass, in public C ABI order.
    _fields_ = [
        ('size', ctypes.c_size_t),
        ('destroy', Destroy),
        ('save_as', ctypes.CFUNCTYPE(ctypes.c_int, Pointer, CString, CString, CString)),
    ]


def public_api(handle, api_type):
    return ctypes.cast(handle, ctypes.POINTER(ctypes.POINTER(api_type))).contents.contents


def uri(path):
    return Path(path).as_uri().encode()


def export_pdf(library, source, target):
    kit = ctypes.CDLL(library, mode=ctypes.RTLD_GLOBAL)
    initialize = kit.libreofficekit_hook_2
    initialize.argtypes = [CString, CString]
    initialize.restype = Pointer
    office = initialize(os.fsencode(Path(library).parent), uri(Path.cwd() / 'profile'))
    if not office:
        raise RuntimeError('LibreOfficeKit initialization failed')
    office_api = public_api(office, OfficeApi)
    try:
        document = office_api.load(office, uri(source))
        if not document:
            reason = office_api.get_error(office) or b'Unable to open document'
            raise RuntimeError(reason.decode(errors='replace'))
        document_api = public_api(document, DocumentApi)
        try:
            if not document_api.save_as(document, uri(target), b'pdf', None):
                reason = office_api.get_error(office) or b'Unable to export PDF'
                raise RuntimeError(reason.decode(errors='replace'))
        finally:
            document_api.destroy(document)
    finally:
        office_api.destroy(office)


if __name__ == '__main__':
    try:
        export_pdf(*sys.argv[1:])
    except (OSError, RuntimeError) as error:
        sys.exit(str(error))
