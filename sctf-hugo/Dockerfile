FROM alpine:latest as hugo

LABEL maintainer="Matt Fisher <mfish551.mf@gmail.com>"

RUN apk add --no-cache \
    curl \
    git \
    hugo 

WORKDIR /src

RUN git clone https://github.com/floppyfish14/sctf-files.git /src
RUN hugo -D -d=/src -s=/src

FROM nginx

COPY --from=hugo /src/* /usr/share/nginx/html/
