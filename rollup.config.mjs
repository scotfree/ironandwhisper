  import typescript from '@rollup/plugin-typescript';

  export default {
    input: 'src/ts/Game.ts',
    output: {
      file: 'modules/js/Game.js',
      format: 'es',
      sourcemap: false,
      inlineDynamicImports: true,
      // BGA's pre-release checklist wants a copyright header on every source
      // file, and this is the file that ships.
      banner: `/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */`,
    },
    plugins: [
      typescript({
        tsconfig: './tsconfig.json',
        outDir: 'modules/js',
      }),
    ],
    treeshake: false,
  };