# SPDX-License-Identifier: AGPL-3.0-or-later
# Web server image: serves public/ (static, hashed assets) and the generated theme volume,
# forwards everything else to PHP-FPM. Runs unprivileged on port 8080.

FROM node:24-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY tsconfig.json vite.config.ts ./
COPY frontend ./frontend
COPY translations ./translations
COPY tests/vectors ./tests/vectors
RUN npx vite build

FROM nginxinc/nginx-unprivileged:1.29-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /src/public/build /usr/share/quietlink/public/build
EXPOSE 8080
