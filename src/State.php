<?php

namespace Eg\AsyncHttp;

enum State:int
{
    case READY = 1;
    case SENDING = 2;
    case LOADING = 3;
    case DONE = 4;


}