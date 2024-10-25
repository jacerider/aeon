const gulp = require('gulp');
const { parallel, series } = require('gulp');
const gutil = require('gulp-util');
const cache = require('gulp-cached');
const fs = require('fs');
const path = require('path');
const extend = require('extend');
const rename = require('gulp-rename');
const execSync = require('child_process').execSync;
const sass = require('gulp-sass')(require('sass'));
const glob = require('gulp-sass-glob');
const autoprefixer = require('gulp-autoprefixer');
const browserSync = require('browser-sync').create();
const sourcemaps = require('gulp-sourcemaps');
const uglify = require('gulp-uglify');
const eslint = require('gulp-eslint');
const babel = require('gulp-babel');
let ddevStatus = false;
let watchStatus = false;
let drupalInfo;
let url = null;
let drushCommand = 'drush';
let root = gutil.env.root;
let gulpStylelint = require('gulp-stylelint');
let config = require('./config/dev/config');

// If config.js exists, load that config for overriding certain values below.
function loadConfig() {
  if (fs.existsSync('./config/dev/config.local.json')) {
    config = extend(true, config, require('./config/dev/config.local'));
  }
  return config;
}
loadConfig();

function drupal(cb) {
  let command = drushCommand + ' status --format=json';
  let localRoot = testDir(splitPath(__dirname));
  if (root) {
    localRoot = testDir(splitPath(root));
    process.chdir(root);
  }
  drupalInfo = JSON.parse(execSync(command).toString());
  drupalInfo.root = localRoot + '/web';
  cb();
}

function exo(cb) {
  const root = process.env.DDEV_EXTERNAL_ROOT || drupalInfo['root'];
  let command = drushCommand + ' exo-scss';
  if (ddevStatus) {
    command = 'ddev exec "export DDEV_EXTERNAL_ROOT=' + root + ' && drush exo-scss"';
  }
  else if (root) {
    command += ' --root="' + root + '"';
  }
  execSync(command);
  config.css.includePaths.push(root + '/' + drupalInfo['site'] + '/files/exo');
  config.components.css.includePaths.push(root + '/' + drupalInfo['site'] + '/files/exo');
  cb();
}

function js(cb) {
  return gulp.src(config.js.src)
    .pipe(eslint({
      configFile: 'config/dev/.eslintrc',
      useEslintrc: false
    }))
    .pipe(eslint.format())
    .pipe(babel({
        presets: ['@babel/preset-env']
    }))
    .pipe(uglify())
    .pipe(gulp.dest(config.js.dest))
    .pipe(watchStatus ? browserSync.stream() : gutil.noop());
}

function css(cb) {
  delete cache.caches['componentCss'];
  return gulp.src(config.css.src)
    .pipe(glob())
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'compressed',
      includePaths: config.css.includePaths,
      silenceDeprecations: ['import', 'legacy-js-api'],
    }).on('error', sass.logError))
    .pipe(autoprefixer({
      browserlist: ['last 2 versions'],
      cascade: false
    }))
    .pipe(sourcemaps.write('./'))
    .pipe(gulp.dest(config.css.dest))
    .pipe(watchStatus ? browserSync.stream() : gutil.noop())
    .on('finish', function () {
      return gulp
        .src(config.css.src)
        .pipe(gulpStylelint({
          failAfterError: false,
          reporters: [
            { formatter: 'string', console: true },
          ],
          debug: true
        }))
        ;
    });
}

function componentJs(cb) {
  return gulp.src(config.components.js.src)
    .pipe(eslint({
      configFile: 'config/dev/.eslintrc',
      useEslintrc: false
    }))
    .pipe(eslint.format())
    .pipe(babel({
        presets: ['@babel/preset-env']
    }))
    .pipe(uglify())
    .pipe(rename(function (path) {
      path.dirname = path.dirname.replace('src/scripts', '');
    }))
    .pipe(gulp.dest(config.components.js.dest))
    .pipe(watchStatus ? browserSync.stream() : gutil.noop());
}

function componentCss(cb) {
  return gulp.src(config.components.css.src)
    .pipe(glob())
    .pipe(cache('componentCss'))
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'compressed',
      includePaths: config.components.css.includePaths,
      silenceDeprecations: ['import', 'legacy-js-api'],
    }).on('error', sass.logError))
    .pipe(autoprefixer({
      browserlist: ['last 2 versions'],
      cascade: false
    }))
    .pipe(rename(function (path) {
      path.dirname = path.dirname.replace('src/styles', '');
    }))
    .pipe(sourcemaps.write('./'))
    .pipe(gulp.dest(config.components.css.dest))
    .pipe(watchStatus ? browserSync.stream() : gutil.noop())
    .on('finish', function () {
      return gulp
        .src(config.components.css.src)
        .pipe(gulpStylelint({
          failAfterError: false,
          reporters: [
            { formatter: 'string', console: true },
          ],
          debug: true
        }))
        ;
    });
}

function clearCache(cb) {
  execSync(drushCommand + ' php-eval "\Drupal::service(\'twig\')->invalidate();" && ' + drushCommand + ' cc render');
  cb();
}

function enableDdev(cb) {
  const ddevConfig = JSON.parse(execSync('ddev describe -j 2>/dev/null').toString());
  if (typeof ddevConfig.raw.status !== 'undefined' && ddevConfig.raw.status === 'running' && typeof ddevConfig.raw.httpurl !== 'undefined') {
    config.browserSync.proxy = ddevConfig.raw.primary_url;
    url = ddevConfig.raw.hostname;
  }
  else {
    throw new Error('DDEV not running. Try running "ddev start".');
  }
  drushCommand = 'ddev drush';
  ddevStatus = true;
  cb();
}

function enableWatch(cb) {
  watchStatus = true;
  cb();
}

function watch(cb) {
  if (watchStatus) {
    browserSync.init({
      ui: false,
      proxy: config.browserSync.proxy,
      port: config.browserSync.port,
      open: config.browserSync.openAutomatically,
      notify: config.browserSync.notify,
      listen: url,
    });
    gulp.watch(config.css.src, css);
    gulp.watch(config.js.src, js);
    gulp.watch(config.components.css.src, componentCss);
    gulp.watch(config.components.js.src, componentJs);
    gulp.watch(config.templates.src, clearCache).on(
        'change',
        browserSync.reload
    );
  }
  else {
    cb();
  }
}

function splitPath(path) {
  var parts = path.split(/(\/|\\)/);
  if (!parts.length) return parts;
  return !parts[0].length ? parts.slice(1) : parts;
}

function testDir(parts) {
  if (parts.length === 0) return null;
  var p = parts.join('');
  var itdoes = fs.existsSync(path.join(p, '.ddev'));
  return itdoes ? p.slice(0, -1) : testDir(parts.slice(0, -1));
}

exports.default = series(drupal, exo, parallel(js, css, componentCss, componentJs));
exports.watch = series(enableWatch, drupal, exo, parallel(js, css, componentCss, componentJs), watch);

// Should be called outside of DDEV.
exports.ddev = series(enableDdev, drupal, exo, parallel(js, css, componentCss, componentJs));
exports.ddevWatch = series(enableWatch, enableDdev, drupal, exo, parallel(js, css, componentCss, componentJs), watch);
