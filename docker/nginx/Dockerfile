# SPDX-License-Identifier: AGPL-3.0-or-later
# Web server image: serves public/ (static, hashed assets) and the generated theme volume,
# forwards everything else to PHP-FPM. Runs unprivileged on port 8080.

FROM node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY tsconfig.json vite.config.ts ./
COPY frontend ./frontend
COPY translations ./translations
COPY tests/vectors ./tests/vectors
RUN npx vite build

FROM nginxinc/nginx-unprivileged:1.29-alpine@sha256:0c79d56aee561a1d81c63f00eee5fb5fe29279560cdc55e91425133104c7fbe6
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/errors /usr/share/quietlink/__errors
COPY --from=assets /src/public/build /usr/share/quietlink/public/build
EXPOSE 8080
