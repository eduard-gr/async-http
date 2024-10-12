<?php

namespace Eg\AsyncHttp;

enum State:int
{
    case CONNECTING = 0;
    case WAIT_FOR_WRITE = 1;
    case READY_TO_WRITING = 2;
    case WAIT_FOR_READ = 3;
    case READING_STATUS_LINE = 4;
    case READING_HEADERS = 5;
    case READING_BODY = 6;
    case READING_BODY_ENCODED_BY_SIZE = 7;

	case READING_BODY_CHUNKED_SIZE = 8;
    case READING_BODY_CHUNKED_BODY = 9;
    case READING_BODY_CHUNKED_COMPLETION = 10;

    case READING_BODY_TO_END = 11;
    case DONE = 777;

}