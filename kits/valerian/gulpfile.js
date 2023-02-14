const gulp = require('gulp');
const { parallel, series } = require('gulp');
const noop = require('gulp-noop');
const fs = require('fs');
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
let config = require('./config/dev/config.json');
let ddevStatus = false;
let watchStatus = false;
let drupalInfo;
let url = process.env.DDEV_HOSTNAME || null;
let drushCommand = 'drush';
let gulpStylelint = require('gulp-stylelint');

// If config.js exists, load that config for overriding certain values below.
function loadConfig() {
  if (fs.existsSync('./config/dev/config.local.json')) {
    config = extend(true, config, require('./config/dev/config.local'));
  }
  return config;
}
loadConfig();

function drupal(cb) {
  drupalInfo = JSON.parse(execSync(drushCommand + ' status --format=json').toString());
  cb();
}

function exo(cb) {
  const root = process.env.DDEV_EXTERNAL_ROOT || drupalInfo['root'];
  if (ddevStatus) {
    execSync('ddev exec "export DDEV_EXTERNAL_ROOT=' + root + ' && drush exo-scss"');
  }
  else {
    execSync(drushCommand + ' exo-scss');
  }
  config.css.includePaths.push(root + '/' + drupalInfo['site'] + '/files/exo');
  config.components.css.includePaths.push(root + '/' + drupalInfo['site'] + '/files/exo');
  cb();
}

function js(cb) {
  gulp.src(config.js.src)
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
    .pipe(watchStatus ? browserSync.stream() : noop());

  cb();
}

function css(cb) {
  gulp.src(config.css.src)
    .pipe(glob())
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'compressed',
      includePaths: config.css.includePaths
    }).on('error', sass.logError))
    .pipe(autoprefixer({
      browserlist: ['last 2 versions'],
      cascade: false
    }))
    .pipe(sourcemaps.write('./'))
    .pipe(gulp.dest(config.css.dest))
    .pipe(watchStatus ? browserSync.stream() : noop())
    .on('finish', function lintCssTask() {
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
    ;
  cb();
}

function componentJs(cb) {
  gulp.src(config.components.js.src)
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
    .pipe(watchStatus ? browserSync.stream() : noop());
  cb();
}

function componentCss(cb) {
  gulp.src(config.components.css.src)
    .pipe(glob())
    .pipe(sourcemaps.init())
    .pipe(sass({
      outputStyle: 'compressed',
      includePaths: config.components.css.includePaths
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
    .pipe(watchStatus ? browserSync.stream() : noop())
    .on('finish', function lintCssTask() {
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
    ;
  cb();
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

exports.default = series(drupal, exo, parallel(js, css, componentCss, componentJs));
exports.watch = series(enableWatch, drupal, exo, parallel(js, css, componentCss, componentJs), watch);

// Should be called outside of DDEV.
exports.ddev = series(drupal, enableDdev, exo, parallel(js, css, componentCss, componentJs));
exports.ddevWatch = series(drupal, enableWatch, enableDdev, exo, parallel(js, css, componentCss, componentJs), watch);
