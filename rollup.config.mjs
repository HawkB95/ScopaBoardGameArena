  import typescript from '@rollup/plugin-typescript';

  // Compila src/ts/Game.ts in un unico modulo ES (modules/js/Game.js) caricato da BGA
  export default {
    input: 'src/ts/Game.ts',
    output: {
      file: 'modules/js/Game.js',
      format: 'es',
      sourcemap: false,
      inlineDynamicImports: true,
    },
    plugins: [
      typescript({
        tsconfig: './tsconfig.json',
        outDir: 'modules/js',
      }),
    ],
    treeshake: false,
  };